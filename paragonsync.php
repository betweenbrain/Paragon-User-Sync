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

		$userId           = $options['user']->id;
		$individualNumber = $this->app->input->get('surname', '');

		if ($userId)
		{
			try
			{
				// Clear existing profile entries
				$query = $this->db->getQuery(true)
					->delete($this->db->quoteName('#__user_profiles'))
					->where($this->db->quoteName('user_id') . ' = ' . (int) $userId)
					->where($this->db->quoteName('profile_key') . ' LIKE ' . $this->db->quote('profile.%'));
				$this->db->setQuery($query);
				$this->db->execute();

				// Get updated profile data from the API and insert it into the database
				$member = $this->memberDetails($options['user']->username);

				$tuples = array();
				$order  = 1;

				foreach ($member as $k => $v)
				{
					$tuples[] = '(' . $userId . ', ' . $this->db->quote('profile.' . $k) . ', ' . $this->db->quote(json_encode($v)) . ', ' . $order++ . ')';
				}

				$this->db->setQuery('INSERT INTO #__user_profiles VALUES ' . implode(', ', $tuples));
				$this->db->execute();

			} catch (RuntimeException $e)
			{
				$this->_subject->setError($e->getMessage());

				return false;
			}
		}

		return true;

	}

	/**
	 * Send data back to Paragon when the user saves their profile
	 *
	 * @param $data
	 * @param $isNew
	 * @param $result
	 * @param $error
	 *
	 * @return bool
	 */
	public function onUserAfterSave($data, $isNew, $result, $error)
	{
		$userId = JArrayHelper::getValue($data, 'id', 0, 'int');

		if ($userId && $result && isset($data['profile']) && (count($data['profile'])))
		{
			foreach ($data['profile'] as $k => $v)
			{

			}
		}

		return true;
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
