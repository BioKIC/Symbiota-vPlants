<?php
include_once("OccurrenceManager.php");
include_once("OccurrenceAccessStats.php");

class OccurrenceListManager extends OccurrenceManager{

	private $recordCount = 0;
	private $sortArr = array();

 	public function __construct(){
 		parent::__construct();
 	}

	public function __destruct(){
 		parent::__destruct();
	}

	public function getSpecimenMap($pageRequest,$cntPerPage){
		$retArr = Array();
		$canReadRareSpp = false;
		if($GLOBALS['USER_RIGHTS']){
			if($GLOBALS['IS_ADMIN'] || array_key_exists("CollAdmin", $GLOBALS['USER_RIGHTS']) || array_key_exists("RareSppAdmin", $GLOBALS['USER_RIGHTS']) || array_key_exists("RareSppReadAll", $GLOBALS['USER_RIGHTS'])){
				$canReadRareSpp = true;
			}
		}
		$occArr = array();
		$sqlWhere = $this->getSqlWhere();
		if(!$this->recordCount || $this->reset) $this->setRecordCnt($sqlWhere);
		$sql = 'SELECT o.occid, c.collid, c.institutioncode, c.collectioncode, c.collectionname, c.icon, o.institutioncode AS instcodeoverride, o.collectioncode AS collcodeoverride, '.
			'o.catalognumber, o.family, o.sciname, o.scientificnameauthorship, o.tidinterpreted, o.recordedby, o.recordnumber, o.eventdate, o.year, o.startdayofyear, o.enddayofyear, '.
			'o.country, o.stateprovince, o.county, o.locality, o.decimallatitude, o.decimallongitude, o.localitysecurity, o.localitysecurityreason, '.
			'o.habitat, o.substrate, o.minimumelevationinmeters, o.maximumelevationinmeters, o.observeruid, c.sortseq '.
			'FROM omoccurrences o LEFT JOIN omcollections c ON o.collid = c.collid ';
		$sql .= $this->getTableJoins($sqlWhere).$sqlWhere;
		//Don't allow someone to query all occurrences if there are no conditions
		if(!$sqlWhere) $sql .= 'WHERE o.occid IS NULL ';
		if($this->sortArr) $sql .= 'ORDER BY '.implode(',',$this->sortArr);
		else{
			$sql .= 'ORDER BY c.sortseq, c.collectionname ';
			$pageRequest = ($pageRequest - 1)*$cntPerPage;
		}
		$sql .= ' LIMIT '.$pageRequest.",".$cntPerPage;
		//echo "<div>Spec sql: ".$sql."</div>";
		$result = $this->conn->query($sql);
		if($result){
			while($row = $result->fetch_object()){
				$securityClearance = ($row->localitysecurity?false:true);
				if(!$securityClearance){
					if($canReadRareSpp) $securityClearance = true;
					elseif(array_key_exists("CollEditor", $GLOBALS['USER_RIGHTS']) && in_array($row->collid,$GLOBALS['USER_RIGHTS']["CollEditor"])) $securityClearance = true;
					elseif(array_key_exists("RareSppReader", $GLOBALS['USER_RIGHTS']) && in_array($row->collid,$GLOBALS['USER_RIGHTS']["RareSppReader"])) $securityClearance = true;
				}
				$retArr[$row->occid]['collid'] = $row->collid;
				$retArr[$row->occid]['instcode'] = $this->cleanOutStr($row->institutioncode);
				if($row->instcodeoverride){
					if(!$retArr[$row->occid]['instcode']) $retArr[$row->occid]['instcode'] = $row->instcodeoverride;
					elseif($retArr[$row->occid]['instcode'] != $row->instcodeoverride) $retArr[$row->occid]['instcode'] .= '-'.$row->instcodeoverride;
				}
				$retArr[$row->occid]['collcode'] = $this->cleanOutStr($row->collectioncode);
				if($row->collcodeoverride){
					if(!$retArr[$row->occid]['collcode']) $retArr[$row->occid]['collcode'] = $row->collcodeoverride;
					elseif($retArr[$row->occid]['collcode'] != $row->collcodeoverride) $retArr[$row->occid]['collcode'] .= '-'.$row->collcodeoverride;
				}
				$retArr[$row->occid]['collname'] = $this->cleanOutStr($row->collectionname);
				$retArr[$row->occid]['icon'] = $row->icon;
				$retArr[$row->occid]["catnum"] = $this->cleanOutStr($row->catalognumber);
				$retArr[$row->occid]["family"] = $this->cleanOutStr($row->family);
				if($securityClearance || $row->localitysecurity == 1){
					$retArr[$row->occid]["sciname"] = ($row->sciname?$this->cleanOutStr($row->sciname):'undetermined');
					$retArr[$row->occid]["tid"] = $row->tidinterpreted;
					$retArr[$row->occid]["author"] = $this->cleanOutStr($row->scientificnameauthorship);
				}
				$retArr[$row->occid]["collector"] = $this->cleanOutStr($row->recordedby);
				$retArr[$row->occid]["country"] = $this->cleanOutStr($row->country);
				$retArr[$row->occid]["state"] = $this->cleanOutStr($row->stateprovince);
				$retArr[$row->occid]["county"] = $this->cleanOutStr($row->county);
				$retArr[$row->occid]["obsuid"] = $row->observeruid;
				$retArr[$row->occid]['localitysecurity'] = $row->localitysecurity;
				if($securityClearance || $row->localitysecurity == 2){
					$locStr = str_replace('.,',',',$row->locality);
					if($row->decimallatitude && $row->decimallongitude) $locStr .= ', '.$row->decimallatitude.' '.$row->decimallongitude;
					$locStr = $this->cleanOutStr(trim($locStr,' ,;'));
					$retArr[$row->occid]["locality"] = $locStr;
					$retArr[$row->occid]["collnum"] = $this->cleanOutStr($row->recordnumber);
					$retArr[$row->occid]["date"] = $row->eventdate;
					$retArr[$row->occid]["habitat"] = $this->cleanOutStr($row->habitat);
					$retArr[$row->occid]['substrate'] = $this->cleanOutStr($row->substrate);
					$elevStr = $row->minimumelevationinmeters;
					if($row->maximumelevationinmeters) $elevStr .= ' - '.$row->maximumelevationinmeters;
					$retArr[$row->occid]["elev"] = $elevStr;
					$occArr[] = $row->occid;
				}
				else{
					$retArr[$row->occid]["locality"] = 'PROTECTED';
				}
			}
			$result->free();
		}
		if($occArr){
			$this->setImages($occArr,$retArr);
			$statsManager = new OccurrenceAccessStats();
			$statsManager->recordAccessEventByArr($occArr,'list');
		}
		return $retArr;
	}

	private function setImages($occArr,&$retArr){
		$sql = 'SELECT occid, thumbnailurl FROM images WHERE occid IN('.implode(',',$occArr).') ORDER BY occid, sortsequence';
		$rs = $this->conn->query($sql);
		$previousOccid = 0;
		while($r = $rs->fetch_object()){
			if($r->occid != $previousOccid) $retArr[$r->occid]['img'] = $r->thumbnailurl;
			$previousOccid = $r->occid;
		}
		$rs->free();
	}

	private function setRecordCnt($sqlWhere){
		if($sqlWhere){
			$sql = "SELECT COUNT(DISTINCT o.occid) AS cnt FROM omoccurrences o ".$this->getTableJoins($sqlWhere).$sqlWhere;
			//echo "<div>Count sql: ".$sql."</div>";
			$result = $this->conn->query($sql);
			if($result){
				if($row = $result->fetch_object()){
					$this->recordCount = $row->cnt;
				}
				$result->free();
			}
		}
	}

	public function getRecordCnt(){
		return $this->recordCount;
	}

	public function addSort($field,$direction){
		$this->sortArr[] = trim($field.' '.$direction);
	}

	public function getCloseTaxaMatch($name){
		$retArr = array();
		$searchName = $this->cleanInStr($name);
		$sql = 'SELECT tid, sciname FROM taxa WHERE soundex(sciname) = soundex(?)';
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param('s', $searchName);
		$stmt->execute();
		$stmt->bind_result($tid, $sciname);
		while($stmt->fetch()){
			if($searchName != $sciname) $retArr[$tid] = $sciname;
		}
		$stmt->close();
		return $retArr;
	}
}
?>