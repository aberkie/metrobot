<?php

include_once '../vendor/autoload.php';

use GuzzleHttp\Client;


$bot = new Metrobot();

$bot->run();

class Metrobot {

	private $pretext;
	private $wmata;
	private $slack;
	private $config;
	private $mode;
	private $slack_message;
	private $title;
	private $error;

	function __construct(){
		$this->config = json_decode(file_get_contents('../config.json'));

		$this->wmata = new Client([
			'base_uri' => 'https://api.wmata.com/'
		]);

		$this->slack = new Client([
			'base_uri' => $this->config->slack_web_hook
		]);
	}

	function run()
	{
		$text = (isset($_REQUEST['text']) ? urldecode($_REQUEST['text']) : '' );
		if($text == 'delays')
		{
			$this->mode = 'RAIL_INCIDENTS';
		} elseif(strpos(strtolower($text), 'next') !== false)  {
			$this->mode = 'NEXT_TRAIN';
		}

		$this->pretext .= "/metro $text";

		switch($this->mode)
		{
			case 'NEXT_TRAIN':
				$this->getNextTrain($text);
				break;

			case 'RAIL_INCIDENTS':
				$this->getRailIncidents();
				break;

			default:
				$this->slack_message .= "*No command sent, so here are the metro delays: * \n";
				$this->getRailIncidents();
				break;
		}
	}

	function getStationsFromString($text)
	{
		$station_name = substr($text, 5);
		$stations = $this->getStations();

		$possible_matches = array();

		foreach($stations as $station)
		{
			if(levenshtein($station_name, strtolower($station->Name)) < 3)
			{
				$possible_matches[] = $station;
			}
		}
	
		return $possible_matches;

	}

	function getNextTrain($text)
	{

		$matches = $this->getStationsFromString($text);

		if(count($matches))
		{
			$stations = array();
			$station_name = "";
			foreach($matches as $station)
			{
				$station_name = $station->Name;
				$stations[] = $station->Code;
			}

			$station_codes = implode(',', $stations);

			$next_trains = $this->wmata->get("StationPrediction.svc/json/GetPrediction/$station_codes",[
				'query' => ['api_key' => $this->config->wmata_api_key]
			]);

			$this->title .= "Next Trains for $station_name";

			$next_trains = json_decode($next_trains->getBody());
			foreach($next_trains->Trains as $train)
			{
				$this->slack_message .= $train->Line . " | ". $train->DestinationName. " | ". $train->Min." \n";
			}

			$this->sendSlackMessage($this->slack_message);

		} else {
			$this->error = true;
			$this->slack_message = "No matching station found :confused: ";
			$this->sendSlackMessage($this->slack_message);
		}
	}

	function getStations()
	{
		$cache = new Gilbitron\Util\SimpleCache();
		$cache->cache_path = 'cache/';
		$cache->cache_time = 86400; //cache for one day
		if($data = $cache->get_cache('all_stations')){
			 $data = json_decode($data);
		} else {
			$stations = $this->wmata->get('Rail.svc/json/jStations',[
				'query' => ['api_key' => $this->config->wmata_api_key]
			]);
			$stations = $stations->getBody();

			$cache->set_cache('all_stations', $stations);

			$data = json_decode($stations);
		}

		return $data->Stations;
	}


	function getRailIncidents()
	{
		$response = $this->wmata->get('Incidents.svc/json/Incidents', [
			'query' => ['api_key' => 'pxzh6cndhj7qb3p6jnzbvgn7']
		]);
		$incidents = json_decode($response->getBody());
		$this->title = "Current Metro Delays";
		if(count($incidents->Incidents))
		{
			foreach($incidents->Incidents as $incident)
			{
				$this->slack_message .= $incident->Description ." \n";
			}
		} else {
			$this->slack_message .= "No current delays or incidents! \n";
		}

		$this->sendSlackMessage($this->slack_message);
	}

	function sendSlackMessage()
	{
		//Set up message attachment
		$attachment = array(
			array(
				'fallback' => $this->slack_message,
				'pretext' => $this->pretext,
				'text' => $this->slack_message,
				'title' => $this->title
			)
		);

		//Set up slack message payload
		$payload = array();
		$payload['username'] = "MetroBot";
		$payload['icon_emoji'] = ":train:";
		$payload['channel'] = $this->getRecipient();
		$payload['attachments'] = $attachment;
		$message = $this->slack->post('', ['body' => json_encode($payload)]);
	}

	function getRecipient()
	{
		$from = $_REQUEST['channel_name'];
		if($from == 'directmessage')
		{
			return '@'.$_REQUEST['user_name'];
		} else {
			return '#'.$_REQUEST['channel_name'];
		}
	}

}


