<?php defined('_JEXEC') or die;

/**
 * File       paragonsync.php
 * Created    10/31/14 12:21 PM
 * Author     Matt Thomas | matt@betweenbrain.com | http://betweenbrain.com
 * Support    https://github.com/betweenbrain/
 * Copyright  Copyright (C) 2014 betweenbrain llc. All Rights Reserved.
 * License    GNU GPL v2 or later
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
		$this->client        = new SoapClient('http://178.251.168.55:8013/ParagonMembershipWeb.svc?wsdl');
		$this->filter        = new JFilterInput;
		$this->membSysConfig = array(
			'membDBConfig' => array(
				'IntegratedSecurity' => 'true',
				'MembDbDataPath'     => 'c:\\sqldata\\',
				'MembDbDatabaseName' => 'MembTrain',
				'MembDbServer'       => 'ROSLSQL02\SqlExpress'
			)
		);
		$this->user          = JFactory::getUser();
		// Load the language file on instantiation
		$this->loadLanguage();
	}

	/**
	 * Update user profile from API after logging in
	 *
	 * @param $user
	 * @param $options
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

		$groups = JUserHelper::getUserGroups($userId);

		if (strtolower($member->Status) != 's')
		{
			foreach ($this->memberFinancialDetails($member) as $detail)
			{
				if (!in_array($detail->FeeCode, $groups))
				{
					JUserHelper::addUserToGroup($userId, $this->userGroups()[$detail->FeeCode]);
				}

			}

			return true;
		}

		unset($groups['Registered']);

		foreach ($groups as $group)
		{
			JUserHelper::removeUserFromGroup($userId, $group->id);
		}

		return true;
	}

	/**
	 * Returns a list of all available user groups
	 */
	private function userGroups()
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

		return $this->client->getMembersFinancialDetails($params)->getMembersFinancialDetailsResult;
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
