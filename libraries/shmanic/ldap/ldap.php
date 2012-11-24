<?php
/**
 * PHP Version 5.3
 *
 * @package     Shmanic.Libraries
 * @subpackage  Ldap
 * @author      Shaun Maunder <shaun@shmanic.com>
 *
 * @copyright   Copyright (C) 2011-2012 Shaun Maunder. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('JPATH_PLATFORM') or die;

/**
 * Provides common pre-defined queries that can be used to retrieve data from
 * the LDAP client. This class extends the funtionality of the client.
 *
 * @package     Shmanic.Libraries
 * @subpackage  Ldap
 * @since       2.0
 */
class SHLdap extends SHLdapBase
{
	/**
	 * Default filter when one is not specified
	 *
	 * @var    string
	 * @since  2.0
	 */
	const DEFAULT_FILTER = '(objectclass=*)';

	/**
	 * The placeholder for username replacement.
	 *
	 * @var    string
	 * @since  2.0
	 */
	const USERNAME_REPLACE = '[username]';

	/**
	 * Use the result object in place of an array when returning data results.
	 *
	 * @var    boolean
	 * @since  2.0
	 */
	const USE_RESULT_OBJECT = true;

	/**
	 * No authentication option.
	 *
	 * @var    integer
	 * @since  2.0
	 */
	const AUTH_NONE = 0;

	/**
	 * Authenticate as username and password.
	 *
	 * @var    integer
	 * @since  2.0
	 */
	const AUTH_USER = 1;

	/**
	 * Authenticate as proxy user.
	 *
	 * @var    integer
	 * @since  2.0
	 */
	const AUTH_PROXY = 2;

	/**
	 * If true then use search to find user.
	 *
	 * @var    boolean
	 * @since  1.0
	 */
	protected $use_search = false;

	/**
	 * Base DN to use for searching (e.g. dc=acme,dc=local / o=company).
	 *
	 * @var    string
	 * @since  1.0
	 */
	protected $base_dn = null;

	/**
	 * User DN or Filter Query (e.g. (sAMAccountName=[username]) / cn=[username],dc=acme,dc=local).
	 *
	 * @var    string
	 * @since  1.0
	 */
	protected $user_qry = null;

	/**
	 * The last successful user distinguished name.
	 *
	 * @var    string
	 * @since  2.0
	 */
	protected $last_user_dn = null;

	/**
	 * Filter to filter by user objects.
	 *
	 * @var    string
	 * @since  2.0
	 */
	protected $all_user_filter = '(objectclass=user)';

	/**
	 * Set to the current status/level of user bind such as none, proxy or user.
	 *
	 * @var     integer
	 * @string  2.0
	 */
	protected $bind_status = self::AUTH_NONE;

	/**
	 * Uses a proxy bind when writing to Ldap.
	 *
	 * @var     boolean
	 * @string  2.0
	 */
	protected $proxy_write = false;

	/**
	 * Find the correct Ldap parameters based on the authorised and configuration
	 * specified. If found then return the successful Ldap object.
	 *
	 * Note: you can use SHLdap->getLastUserDN() for the user DN instead of rechecking again.
	 *
	 * @param   integer|string  $id          Optional configuration record ID.
	 * @param   Array           $authorised  Optional authorisation/authentication options (authenticate, username, password).
	 * @param   JRegistry       $registry    Optional override for platform configuration registry.
	 *
	 * @return  false|SHLdap  An Ldap object on successful authorisation or False on error.
	 *
	 * @since   2.0
	 * @throws  Exception           Configuration problem
	 * @throws  SHExceptionStacked  User or configuration issues (may not be important)
	 */
	public static function getInstance($id = null, array $authorised = array(), JRegistry $registry = null)
	{
		// Get the platform registry config from the factory if required
		$registry = is_null($registry) ? SHFactory::getConfig() : $registry;

		// Get the optional authentication/authorisation options
		$authenticate = JArrayHelper::getValue($authorised, 'authenticate', self::AUTH_NONE);
		$username = JArrayHelper::getValue($authorised, 'username', null);
		$password = JArrayHelper::getValue($authorised, 'password', null);

		// Get all the Ldap configs that are enabled and available
		if (!$configs = SHLdapHelper::getConfig($id, $registry))
		{
			// No configs found - check the log in this case
			throw new Exception(JText::_('LIB_SHLDAP_ERR_10412'), 10412);
		}

		// Check if only one configuration result was found
		if ($configs instanceof JRegistry)
		{
			// Wrap this around an array so we can use the same code below
			$configs = array($configs);
		}

		// Keep a record of any exceptions called and only log them after
		$errors = array();

		// Loop around each of the Ldap configs until one authenticates
		foreach ($configs as $config)
		{
			try
			{
				// Get a new SHLdap object
				$ldap = new SHLdap($config);

				// Check if the authenticate/authentication is successful
				if ($ldap->authenticate($authenticate, $username, $password))
				{
					// This is the correct configuration so return the new client
					return $ldap;
				}

			}
			catch (Exception $e)
			{
				// Add the error to the stack
				$errors[] = $e;
			}

			unset($ldap);
		}

		// Failed to find any configs to match
		throw new SHExceptionStacked(JText::_('LIB_SHLDAP_ERR_10411'), 10411, $errors);
	}

	/**
	 * Method to get certain otherwise inaccessible properties from the ldap object.
	 *
	 * @param   string  $name  The property name for which to the the value.
	 *
	 * @return  mixed  The property value or null.
	 *
	 * @since   2.0
	 */
	public function __get($name)
	{
		switch ($name)
		{
			case 'last_user_dn':
			case 'all_user_filter':
			case 'proxy_write':
			case 'bind_status':
				return $this->$name;
				break;

			case 'bindStatus':
				return $this->bind_status;
				break;

			case 'proxyWrite':
				return $this->proxy_write;
				break;

			case 'lastUserDn':
				return $this->last_user_dn;
				break;

			case 'allUserFilter':
				return $this->all_user_filter;
				break;
		}

		return parent::__get($name);
	}

	/**
	 * Constructor
	 *
	 * @param   object  $configObj  An object of configuration variables
	 *
	 * @since   2.0
	 */
	public function __construct($configObj = null)
	{
		parent::__construct($configObj);
	}

	/**
	 * Overrides JObjects setError to log errors directly to event log
	 * only when an exception object has been passed to error.
	 *
	 * @param   mixed  $error  Exception or error message.
	 *
	 * @return  void
	 *
	 * @since   2.0
	 *
	 * @see JObject::setError()
	 * @deprecated  Delete this method, throw exceptions
	 */
	public function setError($error)
	{
		if ($error instanceof Exception)
		{
			SHLog::add($error, 0, JLog::ERROR, 'ldap');
		}

		parent::setError($error);
	}

	/**
	 * Inform any listening loggers of the debug message.
	 *
	 * @param   string  $message  String to push to stack
	 *
	 * @return  void
	 *
	 * @since  2.0
	 */
	public function addDebug($message)
	{
		// Add the debug message to any listening loggers
		SHLog::add($message, 101, JLog::DEBUG, 'ldap');

		parent::addDebug($message);
	}

	/**
	 * Returns the last successful getUserDN distinguished name.
	 *
	 * @return  string  User distinguished name.
	 *
	 * @since   2.0
	 * @deprecated  Use shldap::lastUserDn
	 */
	public function getLastUserDN()
	{
		return $this->last_user_dn;
	}

	/**
	 * Returns the all user filter.
	 *
	 * @return  string  User filter.
	 *
	 * @since   2.0
	 * @deprecated  Use shldap::allUserFilter
	 */
	public function getAllUserFilter()
	{
		return $this->all_user_filter;
	}

	/**
	 * Attempts to Ldap authorise/authenticate with the parameters specified.
	 *
	 * @param   integer  $authenticate  Authenticate the username and password supplied with the Ldap object.
	 * @param   string   $username      Authorisation/authentication username.
	 * @param   string   $password      Authentication password.
	 *
	 * @return  boolean  True on success or False on failure.
	 *
	 * @since   2.0
	 * @throws  Exception               Configuration error
	 * @throws  SHLdapException         Ldap specific error
	 * @throws  SHExceptionInvaliduser  User invalid error
	 */
	public function authenticate($authenticate = self::AUTH_NONE, $username = null, $password = null)
	{
		// Start the connection procedure (throws an error if fails)
		$this->connect();

		/*
		 * If no authentication is required, then check whether a username
		 * has been specified. If not, then we can just return here. However,
		 * if a username is specified then we want to authorise it instead.
		 */
		if (($authenticate === self::AUTH_NONE) && is_null($username))
		{
			return true;
		}
		// Check if we only want a proxy user.
		elseif ($authenticate === self::AUTH_PROXY)
		{
			if ($this->proxyBind())
			{
				// Proxy bind success
				return true;
			}
		}
		// Assume we need to authenticate or authorise the specified user.
		else
		{
			// If a DN is returned, then this user is successfully authenticated/authorised
			if ($dn = $this->getUserDN($username, $password, ($authenticate === self::AUTH_USER) ? true : false))
			{
				// Successfully authenticated and retrieved User DN.
				return true;
			}
		}

		// Failed to authenticate user
		throw new SHExceptionInvaliduser(JText::_('LIB_SHLDAP_ERR_10401'), 10401, $username);
	}

	/**
	 * Binds using a connect username and password.
	 * Note: Anonymous binds are always allowed here.
	 *
	 * @return  boolean  Returns True on success or False on failure.
	 *
	 * @since   2.0
	 */
	public function proxyBind()
	{
		// Default the bind level to none
		$this->bind_status = self::AUTH_NONE;

		if (parent::proxyBind())
		{
			// Successfully binded so set the level
			$this->bind_status = self::AUTH_PROXY;
			return true;
		}
	}

	/**
	 * Binds to the LDAP directory and returns the operation result.
	 * Note: Anonymous bind can be disabled by passing allowAnonymous(false).
	 *
	 * @param   string  $username  Bind username (anonymous bind if left blank)
	 * @param   string  $password  Bind password (anonymous bind if left blank)
	 *
	 * @return  boolean  Returns True on success or False on failure.
	 *
	 * @since   1.0
	 */
	public function bind($username = null, $password = null)
	{
		// Default the bind level to none
		$this->bind_status = self::AUTH_NONE;

		if (parent::bind($username, $password))
		{
			// Successfully binded so set the level
			$this->bind_status = self::AUTH_USER;
			return true;
		}
	}

	/**
	 * Search directory and subtrees using a base dn and a filter, then returns
	 * the attributes in an array.
	 *
	 * @param   string  $dn          A base dn
	 * @param   string  $filter      Ldap filter to restrict results
	 * @param   array   $attributes  Array of attributes to return (empty array returns all)
	 *
	 * @return  SHLdapResult  Ldap Results.
	 *
	 * @since   2.0
	 * @throws  SHLdapException
	 */
	public function search($dn = null, $filter = null, $attributes = array())
	{
		if (is_null($dn))
		{
			// Use the base distinguished name in place of null value
			$dn = $this->base_dn;
		}

		if (is_null($filter))
		{
			// Use the default filter in place of null value
			$filter = self::DEFAULT_FILTER;
		}

		$result = parent::search($dn, $filter, $attributes);

		if (!self::USE_RESULT_OBJECT || $result === false)
		{
			/*
			 * Not using the special result object OR the operation failed,
			 * therefore lets return now.
			 */
			return $result;
		}

		return new SHLdapResult($result);
	}

	/**
	 * Read directory using given dn and filter, then returns the attributes
	 * in an array.
	 *
	 * @param   string  $dn          Dn of object to read
	 * @param   string  $filter      Ldap filter to restrict results
	 * @param   array   $attributes  Array of attributes to return (empty array returns all)
	 *
	 * @return  SHLdapResult  Ldap Results.
	 *
	 * @since   2.0
	 * @throws  SHLdapException
	 */
	public function read($dn = null, $filter = null, $attributes = array())
	{
		if (is_null($dn))
		{
			// Use the base distinguished name in place of null value
			$dn = $this->base_dn;
		}

		if (is_null($filter))
		{
			// Use the default filter in place of null value
			$filter = self::DEFAULT_FILTER;
		}

		$result = parent::read($dn, $filter, $attributes);

		if (!self::USE_RESULT_OBJECT || $result === false)
		{
			/*
			 * Not using the special result object OR the operation failed,
			 * therefore lets return now.
			 */
			return $result;
		}

		return new SHLdapResult($result);
	}

	/**
	 * Get a users Ldap distinguished name with optional bind authentication.
	 *
	 * @param   string   $username      Authenticating username
	 * @param   string   $password      Authenticating password
	 * @param   boolean  $authenticate  Attempt to authenticate the user (i.e.
	 *						bind the user with the password supplied)
	 *
	 * @return  string  User DN.
	 *
	 * @since   1.0
	 * @throws  Exception               Configuration error
	 * @throws  SHLdapException         Ldap specific error
	 * @throws  SHExceptionInvaliduser  User invalid error
	 */
	public function getUserDN($username = null, $password = null, $authenticate = false)
	{
		if (empty($this->user_qry))
		{
			// No user query specified, cannot proceed
			throw new Exception(JText::_('LIB_SHLDAP_ERR_10301'), 10301);
		}

		$replaced = str_replace(self::USERNAME_REPLACE, $username, $this->user_qry);

		$this->addDebug(
			"Attempt to retrieve user distinguished name using '{$replaced}' " .
			($this->use_search ? ' with search.' : ' with direct bind.')
		);

		// Get a array of distinguished names from either the search or direct bind methods.
		$DNs = $this->use_search ? $this->getUserDnBySearch($username) : $this->getUserDnDirectly($username);

		if (!is_array($DNs) || !count($DNs))
		{
			/*
			 * Cannot find the specified username. We are going to throw
			 * a special user not found error to try to split between
			 * configuration errors and invalid errors. However, this might
			 * still be a configuration error.
			 */
			throw new SHExceptionInvaliduser(JText::_('LIB_SHLDAP_ERR_10302'), 10302, $username);
		}

		// Check if we have to authenticate the distinguished name with a password
		if ($authenticate)
		{
			// Attempt to bind each distinguished name with the specified password then return it
			foreach ($DNs as $dn)
			{
				if ($this->bind($dn, $password))
				{
					// Successfully binded with this distinguished name
					$this->addDebug("Successfully authenticated {$username} with distinguished name {$dn}.");
					$this->last_user_dn = $dn;
					return $dn;
				}
			}

			if ($this->use_search)
			{
				// User found, but was unable to bind with the supplied password
				throw new SHExceptionInvaliduser(JText::_('LIB_SHLDAP_ERR_10303'), 10303, $username);
			}
			else
			{
				// Unable to bind directly to the given distinguished name parameters
				throw new SHExceptionInvaliduser(JText::_('LIB_SHLDAP_ERR_10304'), 10304, $username);
			}

		}
		else
		{

			$result = false;

			if ($this->use_search)
			{
				/* We can be sure the distinguished name(s) exists in the Ldap
				 * directory. However, we cannot be sure if the correct
				 * distinguished name is returned for the specified user without
				 * authenticating. Therefore, we have to assume the first (and
				 * hopefully only) distinguished name is correct.
				 * If the correct configuration has been given and the Ldap
				 * directory is well organised, this will always be correct.
				 */
				$result = $DNs[0];
			}
			else
			{
				/* Unlike searching, binding directly means we cannot be sure
				 * if the distinguished name(s) exists in the Ldap directory.
				 * Therefore, lets attempt to bind with a proxy user, then Ldap
				 * read each distinguished name's entity to check if it exists.
				 * If binding with the proxy user fails, then we have no option
				 * but to assume the first distinguished name exists.
				 */
				if ($this->proxyBind())
				{
					foreach ($DNs as $dn)
					{
						// Check if the distinguished name entity exists
						if (count($this->read($dn, null, array('dn'))))
						{
							// It exists so we assume this is the correct distinguished name.
							$result = $dn;
							break;
						}
					}

					if ($result === false)
					{
						// Failed to find any of the distinguished name(s) in the Ldap directory.
						throw new SHExceptionInvaliduser(JText::_('LIB_SHLDAP_ERR_10305'), 10305, $username);
					}
				}
				else
				{
					// Unable to check Ldap directory, so have to assume the first is correct
					$result = $DNs[0];
				}
			}

			$this->addDebug("Using distinguished name {$result} for user {$username}.");
			$this->last_user_dn = $result;
			return $result;
		}
	}

	/**
	 * Get a user's dn by attempting to search for it in the directory.
	 *
	 * This method uses the query as a filter to find where the user is located in the directory
	 *
	 * @param   string  $username  Authenticating username.
	 *
	 * @return  array  An array containing user DNs.
	 *
	 * @since   1.0
	 * @throws  Exception        Configuration related error
	 * @throws  SHLdapException  Ldap search error
	 */
	public function getUserDnBySearch($username)
	{
		// Fixes special usernames and provides simple protection against ldap injections
		$username 	= SHLdapHelper::escape($username);
		$search 	= str_replace(self::USERNAME_REPLACE, $username, $this->user_qry);

		// Basic check for LDAP filter (i.e. brackets). Could still be a distinguished name.
		if (!preg_match('/\((.)*\)/', $search))
		{
			$search = "({$search})";
		}

		if (empty($this->base_dn))
		{
			// No base distinguished name specified, cannot proceed.
			throw new Exception(JText::_('LIB_SHLDAP_ERR_10321'), 10321);
		}

		// Bind using the proxy user so the user can be found in the Ldap directory.
		if (!$this->proxyBind())
		{
			// Failed to bind with proxy user
			throw new SHLdapException(JText::_('LIB_SHLDAP_ERR_10322'), 10322);
		}

		// Search the directory for the user
		$result = $this->search(null, $search, array($this->ldap_uid));

		$return 	= array();
		$count 		= $result->countEntries();

		// Store the distinguished name for each user found
		for ($i = 0; $i < $count; ++$i)
		{
			$return[] = $result->getValue($i, 'dn', 0);
		}

		return $return;
	}

	/**
	 * Get a user's distinguished name by attempting to replace the username keyword
	 * in the query. Supports multiple distinguished names in a list.
	 *
	 * @param   string  $username  Authenticating username.
	 *
	 * @return  array  An array containing distinguished names.
	 *
	 * @since   1.0
	 * @throws  Exception
	 */
	public function getUserDnDirectly($username)
	{
		$return = array();

		// Fixes special usernames and provides protection against distinguished name injection
		$username = SHLdapHelper::escape($username, true);

		// Replace the username placeholder with the authenticating username
		$search = str_replace(self::USERNAME_REPLACE, $username, $this->user_qry);

		// Splits each of the distinguished names into indivdual elements
		$DNs = explode(';', $search);

		/*
		 * A basic check to ensure a filter isnt being used.
		 * (i.e. distinguished names do not start and end with brackets).
		 */
		if (preg_match('/\((.)*\)/', $search))
		{
			// Cannot continue as brackets are present
			throw new Exception(JText::_('LIB_SHLDAP_ERR_10331'), 10331);
		}

		// We need to find the correct distinguished name from the set of elements
		foreach ($DNs as $dn)
		{
			// Remove whitespacing from the distinguished name and check there is a length > 1
			if ($dn = trim($dn))
			{
				$return[] = $dn;
			}
		}

		return $return;
	}

	/**
	 * Get an array of user detail attributes for the user. By default this method will return the
	 * mapping uid, fullname and email fields. It will also try to get all the attributes required
	 * for group mapping, though this is optional.
	 *
	 * @param   string  $dn          The dn of the user
	 * @param   array   $attributes  An optional array of extra attributes
	 *
	 * @return  array|false  On success shall return an array of attributes, otherwise
	 * 					returns a JException to indicate an error.
	 *
	 * @since   1.0
	 * @deprecated  User adapter should be used
	 */
	public function getUserDetails($dn, $attributes = array())
	{

		JLog::add('SHLdap::getUserDetails is deprecated.', JLog::WARNING, 'deprecated');

		// Call for any LDAP plug-ins that require extra attributes.
		$extras = SHFactory::getDispatcher('ldap')->trigger(
			'onLdapBeforeRead',
			array(&$this, array('dn' => $dn, 'source' => __METHOD__))
		);

		// For each of the LDAP plug-ins returned, merge their extra attributes.
		foreach ($extras as $extra)
		{
			$attributes = array_merge($attributes, $extra);
		}

		// Add both of the uid and fullname to the set of attributes to get.
		$attributes[] = $this->getFullname();
		$attributes[] = $this->getUid();

		// Check for a fake email
		$fakeEmail = (strpos($this->getEmail(), self::USERNAME_REPLACE) !== false) ? true : false;

		// Add the email attribute only if not a fake email is supplied.
		if (!$fakeEmail)
		{
			$attributes[] = $this->getEmail();
		}

		// Re-order array to ensure an LDAP read is successful and no duplicates exist.
		$attributes = array_values(array_unique($attributes));

		// Swap the attribute names to array keys ready for the result
		$return = array_fill_keys($attributes, null);

		// Get Ldap user attributes and check we have a valid result
		$result	= $this->read($dn, null, $attributes);
		if ($result->getDN(0) === false)
		{
			$exception = $this->getError(null, true);

			// Failed to retrieve user attributes or total read fail.
			$this->setError(new SHLdapException(null, 10341, JText::sprintf('LIB_SHLDAP_ERR_10341', $dn, $exception)));

			return false;
		}

		// Lets store the results into the return array
		foreach (array_keys($return) as $attribute)
		{
			$return[$attribute] = $result->getAttribute(0, $attribute);
		}

		if ($fakeEmail)
		{
			// Insert the fake email by replacing the username placeholder with the username from ldap
			$email = str_replace(self::USERNAME_REPLACE, $return[$this->getUid()][0], $this->getEmail());
			$return[$this->getEmail()] = array($email);
		}

		if (!SHLdapHelper::triggerEvent(
			'onLdapAfterRead',
			array(&$this, &$return, array('dn' => $dn,'source' => __METHOD__))
		))
		{
			// Cancelled login due to plug-in
			$this->setError(new SHLdapException(null, 10342, JText::_('LIB_SHLDAP_ERR_10342')));
			return false;
		}

		return $return;
	}

	/**
	 * Get an array of all the nested groups through the use of group recursion.
	 * This is required usually only for Active Directory, however there could
	 * be other LDAP platforms that cannot pick up nested groups.
	 *
	 * @param   array   $searchDNs       Initial user groups (or ones that have already been discovered).
	 * @param   string  $depth           How far to search down until it should give up (0 means unlimited).
	 * @param   array   &$result         Holds the result of every alliteration (by reference).
	 * @param   string  $attribute       The LDAP attribute to store after each ldap search.
	 * @param   string  $queryAttribute  The LDAP filter attribute to query.
	 *
	 * @return  array  All user groups including the initial user groups.
	 *
	 * @since   1.0
	 */
	public function getRecursiveGroups($searchDNs, $depth, &$result, $attribute, $queryAttribute = null)
	{
		$search		= null;
		$next		= array();
		$filters	= array();

		// As this is recursive, we want to be able to specify a optional depth
		--$depth;

		if (!isset($searchDNs))
		{
			return $result;
		}

		foreach ($searchDNs as $dn)
		{
			// Build one or more partial filters from the DN user groups
			$filters[] = $queryAttribute . '=' . $dn;
		}

		if (!count($filters))
		{
			// If there is no filter to process then we are finished
			return $result;
		}

		// Build the full filter using the OR operator
		$search = SHLdapHelper::buildFilter($filters, '|');

		// Search for any groups that also contain the groups we have in the filter
		$results = $this->search(null, $search, array($attribute));

		// Lets process each group that was found
		$entryCount = $results->countEntries();
		for ($i = 0; $i < $entryCount; ++$i)
		{
			$dn = $results->getDN($i);

			// We don't want to re-process a group that was processed previously
			if (!in_array($dn, $result))
			{
				$result[] = $dn;

				// Check if there are more groups we should process from the groups just discovered
				$valueCount = $results->countValues($i, $attribute);
				for ($j = 0; $j < $valueCount; ++$j)
				{
					// We want to process this object
					$value = $results->getValue($i, $attribute, $j);
					$next[] = $value;
				}
			}
		}

		/*
		 * Only start the recursion when we have something to process next
		 * otherwise, we would loop forever.
		 */
		if (count($next) && $depth != 0)
		{
			$this->getRecursiveGroups($next, $depth, $result, $attribute, $queryAttribute);
		}

		return $result;
	}

	/**
	 * Make changes to the attributes within an Ldap distinguished name object.
	 * This method compares the current attribute values against a new changed
	 * set of attribute values and commits the differences.
	 *
	 * @param   string  $dn       Distinguished name of object.
	 * @param   array   $current  An array of attributes containing the current state of the object.
	 * @param   array   $changes  An array of the new/changed attributes for the object.
	 *
	 * @return  boolean  True on success or False on error.
	 *
	 * @since   2.0
	 * @deprecated  User adapter should be used
	 */
	public function makeChanges($dn, array $current, array $changes)
	{

		if (!count($changes))
		{
			// There is nothing to change
			return false;
		}

		$deleteEntries 		= array();
		$addEntries 		= array();
		$replaceEntries		= array();

		foreach ($changes as $key => $value)
		{
			$return = 0;

			// Check this attribute for multiple values
			if (is_array($value))
			{
				/* This is a multiple value attriute and to preserve
				 * order we must replace the whole thing if changes
				 * are required.
				 */
				$modification = false;
				$new = array();
				$count = 0;

				for ($i = 0; $i < count($value); ++$i)
				{

					if ($return = self::checkFieldHelper($current, $key, $count, $value[$i]))
					{
						$modification = true;
					}

					if ($return !== 3 && $value[$i])
					{
						// We don't want to save deletes
						$new[] = $value[$i];
						++$count;
					}
				}

				if ($modification)
				{
					// We want to delete it first
					$deleteEntries[$key] = array();
					if (count($new))
					{
						// Now lets re-add them
						$addEntries[$key] = array_reverse($new);
					}
				}
			}
			else
			{
				/* This is a single value attribute and we now need to
				 * determine if this needs to be ignored, added,
				 * modified or deleted.
				 */
				$return = self::checkFieldHelper($current, $key, 0, $value);

				switch ($return)
				{
					case 1:
						$replaceEntries[$key] = $value;
						break;

					case 2:
						$addEntries[$key] = $value;
						break;

					case 3:
						$deleteEntries[$key] = array();
						break;

				}
			}
		}

		/*
		 * We can now commit the changes to the LDAP server for this DN.
		 */
		$operations	= array('delete' => $deleteEntries, 'add' => $addEntries, 'replace' => $replaceEntries);
		$results 	= array();

		foreach ($operations as $operation => $commit)
		{
			// Check there are some attributes to process for this commit
			if (count($commit))
			{
				$method = "{$operation}Attributes";

				// Commit the Ldap attribute operating
				$result = $this->$method($dn, $commit);

				// Determine whether the operating was a success then log it
				$priority = ($result === false) ? JLog::ERROR : JLog::INFO;

				// Log for audit
				SHLog::add(
					JText::sprintf(
						'LDAP %1$s Attribute (%2$s): %3$s',
						$operation,
						$dn,
						var_export($commit, true)
					), 0, $priority, 'ldap'
				);

				// Add the result to the results array
				$results[] = $result;
			}
		}

		// Check if any of the operations failed
		if (!in_array(false, $results, true))
		{
			return true;
		}
	}

	/**
	 * This method is used as a helper to the makeChanges() method. It checks
	 * whether a field/attribute is up-to-date in the Ldap directory (not live).
	 * The method returns whether it is:
	 * 0: up-to-date, no action required;
	 * 1: attribute exists, but value must be updated;
	 * 2: attribute doesnt exist, needs creating;
	 * 3: attribute exists, but is no longer required and needs deleting.
	 *
	 * @param   array    $current   The current (or old) set of attributes to compare.
	 * @param   string   $key       Key of the attribute.
	 * @param   integer  $interval  The attribute number (in case of multiple values per key).
	 * @param   string   $value     The new attribute value.
	 *
	 * @return  integer  See method description.
	 *
	 * @since   2.0
	 * @deprecated  User adapter should be used
	 */
	protected static function checkFieldHelper(array $current, $key, $interval, $value)
	{
		// Check if the LDAP attribute exists
		if (array_key_exists($key, $current))
		{
			if (isset($current[$key][$interval]))
			{
				if ($current[$key][$interval] == $value)
				{
					// Same value - no need to update
					return 0;
				}
				if (is_null($value) || !$value)
				{
					// We don't want to include a blank or null value
					return 3;
				}
			}

			if (is_null($value) || !$value)
			{
				// We don't want to include a blank or null value
				return 0;
			}

			return 1;
		}
		else
		{
			if (!is_null($value) && $value)
			{
				// We need to create a new LDAP attribute
				return 2;
			}
			else
			{
				// We don't want to include a blank or null value
				return 0;
			}
		}
	}

}
