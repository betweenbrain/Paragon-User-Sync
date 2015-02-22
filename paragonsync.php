<?php defined('_JEXEC') or die;

/**
 * File       paragonsync.php
 * Created    10/31/14 12:21 PM
 * Author     Matt Thomas | matt@betweenbrain.com | http://betweenbrain.com
 * Support    https://github.com/betweenbrain/
 * Copyright  Copyright (C) 2014 betweenbrain llc. All Rights Reserved.
 * License    GNU GPL v2 or later
 */

/**
 * Class PlgUserParagonsync
 *
 * @package  Paragon
 * @since    1.0.0
 */
class PlgUserParagonsync extends JPlugin
{
	/**
	 * Constructor.
	 *
	 * @param   object &$subject The object to observe
	 * @param   array  $config   An optional associative array of configuration settings.
	 *
	 * @since   1.0.0
	 */
	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);

		$this->app           = JFactory::getApplication();
		$this->db            = JFactory::getDbo();
		$this->client        = new SoapClient($this->params->get('client'));
		$this->filter        = new JFilterInput;
		$this->membSysConfig = array(
			'membDBConfig' => array(
				'IntegratedSecurity' => $this->params->get('integratedSecurity'),
				'MembDbDataPath'     => $this->params->get('dbPath'),
				'MembDbDatabaseName' => $this->params->get('dbName'),
				'MembDbServer'       => $this->params->get('dbServer')
			)
		);
		$this->user          = JFactory::getUser();

		// Load the language file on instantiation
		$this->loadLanguage();
	}

	/**
	 * Update user profile from API after logging in
	 *
	 * @param  $user
	 * @param  $options
	 *
	 * @return bool
	 *
	 */
	public function onUserAfterLogin($options)
	{
		// Get updated profile data from the API and insert it into the database
		$member = $this->memberDetails($options['user']->username);
		$userId = $options['user']->id;

		// add the hashed password to the joomla db if responseType is Paragon - added by Waseem Sadiq 17.02.2015
		$this->savePassword($options);

		// Import Joomla user helper
		jimport('joomla.user.helper');

		$assignedGroups  = JUserHelper::getUserGroups($userId);
		$availableGroups = $this->getAllUserGroups();

		//add sha1 password to the session, to be used in com_wrapper override. Luciano jan-13-2015
		$session =& JFactory::getSession();
		$session->set("sha1pass", sha1($_POST['password']));
		$session->set("MemberRef", $options['user']->username);

		// Assign groups if user is not suspended
		if (strtolower($member->Status) != 's')
		{

			// Force memberDetails to always be an array as the API doesn't return consistent data
			if (is_array($this->memberFinancialDetails($member)))
			{
				$memberFinancialDetails = $this->memberFinancialDetails($member);
			}

			if (!is_array($this->memberFinancialDetails($member)))
			{
				$memberFinancialDetails[] = $this->memberFinancialDetails($member);
			}

			foreach ($memberFinancialDetails as $detail)
			{
				if (!array_key_exists($detail->FeeCode, $availableGroups))
				{
					$this->createGroup($detail->FeeCode);

					// Get the available groups again to ensure list is updated
					$availableGroups = $this->getAllUserGroups();
				}

				if (!in_array($availableGroups[$detail->FeeCode]->id, $assignedGroups))
				{
					JUserHelper::addUserToGroup($userId, $availableGroups[$detail->FeeCode]->id);
				}
			}
		}

		// Remove suspended user from all groups associated with their Fee Codes
		if (strtolower($member->Status) == 's')
		{
			foreach ($this->memberFinancialDetails($member) as $detail)
			{
				if (in_array($availableGroups[$detail->FeeCode]->id, $assignedGroups))
				{
					JUserHelper::removeUserFromGroup($userId, $availableGroups[$detail->FeeCode]->id);
				}
			}
		}

		// Update user
		$this->updateUser($userId, $member);

		// Create Mijoshop customer from user if one does not already exist - added by Waseem Sadiq 17.02.2015
		if (MijoShop::get('user')->getOCustomerById($userId) === null)
		{
			MijoShop::get('user')->createOAccountFromJ($options['user']);
		}

		return true;
	}

	/**
	 * Method to create a user group
	 *
	 * @param     $title
	 * @param int $parent
	 */
	private function createGroup($title, $parent = 1)
	{
		jimport('joomla.database.table');
		jimport('joomla.database.table.table');

		$table = JTable::getInstance('usergroup');

		$table->parent_id = $parent;
		$table->title     = $title;
		$table->check();
		$table->store();
	}

	/**
	 * Returns a list of all available user groups
	 *
	 * @return mixed
	 */
	private function getAllUserGroups()
	{
		$query = $this->db->getQuery(true);

		$query
			->select($this->db->quoteName(array('id', 'title')))
			->from($this->db->quoteName('#__usergroups'))
			->order('id ASC');

		$this->db->setQuery($query);

		return $this->db->loadObjectList('title');
	}

	/**
	 * Retrieves the user's financial details from the API
	 *
	 * @param $member
	 *
	 * @return mixed
	 */
	private function memberFinancialDetails($member)
	{
		$params = array(
			'membSysConfig'    => $this->membSysConfig,
			'MemberNumber'     => $member->MemberNumber,
			'IndividualNumber' => $member->IndividualNumber
		);

		return $this->client->getMembersFinancialDetails($params)->getMembersFinancialDetailsResult->MembersFinancialDetails;
	}

	/**
	 * Retrieves the user details object from the API
	 *
	 * @param $username
	 *
	 * @return mixed
	 */
	private function memberDetails($username)
	{
		$params = array(
			'membSysConfig' => $this->membSysConfig,
			'Stats2'        => $username
		);

		return $this->client->getMemberDetailsStats2($params)->getMemberDetailsStats2Result;
	}

	/**
	 * Adds the password to the Joomla user
	 *
	 * @return mixed
	 */
	private function savePassword($options)
	{
		$mainframe    = JFactory::getApplication();
		$responseType = $options['responseType'];

		if ($mainframe->isAdmin())
		{
			return true;
		}

		elseif ($responseType == 'Paragon')
		{
			//hash Password
			$hashedPass = JUserHelper::hashPassword($_POST['password']);
			// username
			$username = $options['user']->username;
			//save Password
			$query = $this->db->getQuery(true);
			$query
				->update('#__users')
				->set("password=" . $this->db->quote($hashedPass))
				->where("username=" . $username);

			$this->db->setQuery($query);
			$result = $this->db->execute();
		}

		return true;
	}

	/**
	 * Updates user details
	 *
	 * @param $userId
	 * @param $data
	 *
	 * @return bool
	 */
	private function updateUser($userId, $member)
	{

		$user = new JUser($userId);

		$data = array(
			"name"  => trim($member->Forename) . ' ' . trim($member->Surname),
			"email" => trim($member->Email)
		);

		// Bind the data.
		if (!$user->bind($data))
		{
			$this->setError($user->getError());

			return false;
		}

		$user->groups = null;

		// Store the data.
		if (!$user->save())
		{
			$this->setError($user->getError());

			return false;
		}

		return true;
	}

}
