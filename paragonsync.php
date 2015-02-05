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

		// Import Joomla user helper
		jimport('joomla.user.helper');

		$assignedGroups  = JUserHelper::getUserGroups($userId);
		$availableGroups = $this->getAllUserGroups();

		// Assign groups if user is not suspended
		if (strtolower($member->Status) != 's')
		{
			foreach ($this->memberFinancialDetails($member) as $detail)
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

			return true;
		}

		// Remove user from all groups associated with their Fee Codes
		foreach ($this->memberFinancialDetails($member) as $detail)
		{
			if (in_array($availableGroups[$detail->FeeCode]->id, $assignedGroups))
			{
				JUserHelper::removeUserFromGroup($userId, $availableGroups[$detail->FeeCode]->id);
			}
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

}
