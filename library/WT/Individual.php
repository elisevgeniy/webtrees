<?php
// Class file for an individual
//
// webtrees: Web based Family History software
// Copyright (C) 2013 webtrees development team.
//
// Derived from PhpGedView
// Copyright (C) 2002 to 2009  PGV Development Team.  All rights reserved.
//
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
//
// $Id$

if (!defined('WT_WEBTREES')) {
	header('HTTP/1.0 403 Forbidden');
	exit;
}

class WT_Individual extends WT_GedcomRecord {
	const RECORD_TYPE = 'INDI';
	const SQL_FETCH   = "SELECT i_gedcom FROM `##individuals` WHERE i_id=? AND i_file=?";
	const URL_PREFIX  = 'individual.php?pid=';

	var $label = '';
	var $highlightedimage = null;
	var $file = '';
	var $age = null;
	var $sex=null;
	var $generation; // used in some lists to keep track of this Person's generation in that list

	// Cached results from various functions.
	private $_spouseFamilies=null;
	private $_childFamilies=null;
	private $_getBirthDate=null;
	private $_getBirthPlace=null;
	private $_getAllBirthDates=null;
	private $_getAllBirthPlaces=null;
	private $_getEstimatedBirthDate=null;
	private $_getDeathDate=null;
	private $_getDeathPlace=null;
	private $_getAllDeathDates=null;
	private $_getAllDeathPlaces=null;
	private $_getEstimatedDeathDate=null;

	// Can the name of this record be shown?
	public function canShowName($access_level=WT_USER_ACCESS_LEVEL) {
		global $SHOW_LIVING_NAMES;

		return $SHOW_LIVING_NAMES>=$access_level || $this->canShow($access_level);
	}

	// Implement person-specific privacy logic
	protected function _canShowByType($access_level) {
		global $SHOW_DEAD_PEOPLE, $KEEP_ALIVE_YEARS_BIRTH, $KEEP_ALIVE_YEARS_DEATH;

		// Dead people...
		if ($SHOW_DEAD_PEOPLE>=$access_level && $this->isDead()) {
			$keep_alive=false;
			if ($KEEP_ALIVE_YEARS_BIRTH) {
				preg_match_all('/\n1 (?:'.WT_EVENTS_BIRT.').*(?:\n[2-9].*)*(?:\n2 DATE (.+))/', $this->gedcom, $matches, PREG_SET_ORDER);
				foreach ($matches as $match) {
					$date=new WT_Date($match[1]);
					if ($date->isOK() && $date->gregorianYear()+$KEEP_ALIVE_YEARS_BIRTH > date('Y')) {
						$keep_alive=true;
						break;
					}
				}
			}
			if ($KEEP_ALIVE_YEARS_DEATH) {
				preg_match_all('/\n1 (?:'.WT_EVENTS_DEAT.').*(?:\n[2-9].*)*(?:\n2 DATE (.+))/', $this->gedcom, $matches, PREG_SET_ORDER);
				foreach ($matches as $match) {
					$date=new WT_Date($match[1]);
					if ($date->isOK() && $date->gregorianYear()+$KEEP_ALIVE_YEARS_DEATH > date('Y')) {
						$keep_alive=true;
						break;
					}
				}
			}
			if (!$keep_alive) {
				return true;
			}
		}
		// Consider relationship privacy (unless an admin is applying download restrictions)
		if (WT_USER_GEDCOM_ID && WT_USER_PATH_LENGTH && $this->getGedcomId()==WT_GED_ID && $access_level=WT_USER_ACCESS_LEVEL) {
			return self::isRelated($this, WT_USER_PATH_LENGTH);
		}
		// No restriction found - show living people to members only:
		return WT_PRIV_USER>=$access_level;
	}

	// For relationship privacy calculations - is this individual a close relative?
	private static function isRelated(WT_Individual $individual, $distance) {
		static $cache = null;

		if (!$cache) {
			$root = WT_Individual::getInstance(WT_USER_GEDCOM_ID);
			$cache = array(
				0 => array($root),
				1 => array(),
			);
			foreach ($root->getFacts('FAM[CS]') as $fact) {
				$cache[1][] = $fact->getTarget();
			}
		}

		// Double the distance, as we count the INDI-FAM and FAM-INDI links separately
		$distance *= 2;

		// Consider each path length in turn
		for ($n=0; $n<=$distance; ++$n) {
			if (!array_key_exists($n, $cache)) {
				$cache[$n] = array();
				if ($n % 2 == 0) {
					// Add FAM->INDI links
					foreach ($cache[$n-1] as $fam) {
						foreach ($fam->getFacts('HUSB|WIFE|CHIL') as $fact) {
							$indi = $fact->getTarget();
							// Don't backtrack
							if (!in_array($indi, $cache[$n-2])) {
								$cache[$n][] = $indi;
							}
						}
					}
					if (in_array($individual, $cache[$n])) {
						return true;
					}
				} else {
					// Add INDI->FAM links
					foreach ($cache[$n-1] as $indi) {
						foreach ($indi->getFacts('FAM[CS]') as $fact) {
							$fam = $fact->getTarget();
							// Don't backtrack
							if (!in_array($fam, $cache[$n-2])) {
								$cache[$n][] = $fam;
							}
						}
					}
				}
			}
		}
		return false;
	}

	// Generate a private version of this record
	protected function createPrivateGedcomRecord($access_level) {
		global $SHOW_PRIVATE_RELATIONSHIPS, $SHOW_LIVING_NAMES;

		$rec='0 @'.$this->xref.'@ INDI';
		if ($SHOW_LIVING_NAMES>=$access_level) {
			// Show all the NAME tags, including subtags
			preg_match_all('/\n1 NAME.*(?:\n[2-9].*)*/', $this->gedcom, $matches);
			foreach ($matches[0] as $match) {
				if (canDisplayFact($this->xref, $this->gedcom_id, $match, $access_level)) {
					$rec.=$match;
				}
			}
		} else {
			$rec.="\n1 NAME ".WT_I18N::translate('Private');
		}
		// Just show the 1 FAMC/FAMS tag, not any subtags, which may contain private data
		preg_match_all('/\n1 (?:FAMC|FAMS) @('.WT_REGEX_XREF.')@/', $this->gedcom, $matches, PREG_SET_ORDER);
		foreach ($matches as $match) {
			$rela=WT_Family::getInstance($match[1]);
			if ($rela && ($SHOW_PRIVATE_RELATIONSHIPS || $rela->canShow($access_level))) {
				$rec.=$match[0];
			}
		}
		// Don't privatize sex.
		if (preg_match('/\n1 SEX [MFU]/', $this->gedcom, $match)) {
			$rec.=$match[0];
		}
		return $rec;
	}

	// Fetch the record from the database
	protected static function fetchGedcomRecord($xref, $gedcom_id) {
		static $statement=null;

		if ($statement===null) {
			$statement=WT_DB::prepare("SELECT i_gedcom FROM `##individuals` WHERE i_id=? AND i_file=?");
		}

		return $statement->execute(array($xref, $gedcom_id))->fetchOne();
	}

	// Static helper function to sort an array of people by birth date
	static function CompareBirtDate($x, $y) {
		return WT_Date::Compare($x->getEstimatedBirthDate(), $y->getEstimatedBirthDate());
	}

	// Static helper function to sort an array of people by death date
	static function CompareDeatDate($x, $y) {
		return WT_Date::Compare($x->getEstimatedDeathDate(), $y->getEstimatedDeathDate());
	}

	// Calculate whether this person is living or dead.
	// If not known to be dead, then assume living.
	public function isDead() {
		global $MAX_ALIVE_AGE;

		// "1 DEAT Y" or "1 DEAT/2 DATE" or "1 DEAT/2 PLAC"
		if (preg_match('/\n1 (?:'.WT_EVENTS_DEAT.')(?: Y|(?:\n[2-9].+)*\n2 (DATE|PLAC) )/', $this->gedcom)) {
			return true;
		}

		// If any event occured more than $MAX_ALIVE_AGE years ago, then assume the person is dead
		if (preg_match_all('/\n2 DATE (.+)/', $this->gedcom, $date_matches)) {
			foreach ($date_matches[1] as $date_match) {
				$date=new WT_Date($date_match);
				if ($date->isOK() && $date->MaxJD() <= WT_SERVER_JD - 365*$MAX_ALIVE_AGE) {
					return true;
				}
			}
			// The individual has one or more dated events.  All are less than $MAX_ALIVE_AGE years ago.
			// If one of these is a birth, the person must be alive.
			if (preg_match('/\n1 BIRT(?:\n[2-9].+)*\n2 DATE /', $this->gedcom)) {
				return false;
			}
		}

		// If we found no conclusive dates then check the dates of close relatives.

		// Check parents (birth and adopted)
		foreach ($this->getChildFamilies(WT_PRIV_HIDE) as $family) {
			foreach ($family->getSpouses(WT_PRIV_HIDE) as $parent) {
				// Assume parents are no more than 45 years older than their children
				preg_match_all('/\n2 DATE (.+)/', $parent->gedcom, $date_matches);
				foreach ($date_matches[1] as $date_match) {
					$date=new WT_Date($date_match);
					if ($date->isOK() && $date->MaxJD() <= WT_SERVER_JD - 365*($MAX_ALIVE_AGE+45)) {
						return true;
					}
				}
			}
		}

		// Check spouses
		foreach ($this->getSpouseFamilies(WT_PRIV_HIDE) as $family) {
			preg_match_all('/\n2 DATE (.+)/', $family->gedcom, $date_matches);
			foreach ($date_matches[1] as $date_match) {
				$date=new WT_Date($date_match);
				// Assume marriage occurs after age of 10
				if ($date->isOK() && $date->MaxJD() <= WT_SERVER_JD - 365*($MAX_ALIVE_AGE-10)) {
					return true;
				}
			}
			// Check spouse dates
			$spouse=$family->getSpouse($this, WT_PRIV_HIDE);
			preg_match_all('/\n2 DATE (.+)/', $spouse->gedcom, $date_matches);
			foreach ($date_matches[1] as $date_match) {
				$date=new WT_Date($date_match);
				// Assume max age difference between spouses of 40 years
				if ($date->isOK() && $date->MaxJD() <= WT_SERVER_JD - 365*($MAX_ALIVE_AGE+40)) {
					return true;
				}
			}
			// Check child dates
			foreach ($family->getChildren(WT_PRIV_HIDE) as $child) {
				preg_match_all('/\n2 DATE (.+)/', $child->gedcom, $date_matches);
				// Assume children born after age of 15
				foreach ($date_matches[1] as $date_match) {
					$date=new WT_Date($date_match);
					if ($date->isOK() && $date->MaxJD() <= WT_SERVER_JD - 365*($MAX_ALIVE_AGE-15)) {
						return true;
					}
				}
				// Check grandchildren
				foreach ($child->getSpouseFamilies(WT_PRIV_HIDE) as $child_family) {
					foreach ($child_family->getChildren(WT_PRIV_HIDE) as $grandchild) {
						preg_match_all('/\n2 DATE (.+)/', $grandchild->gedcom, $date_matches);
						// Assume grandchildren born after age of 30
						foreach ($date_matches[1] as $date_match) {
							$date=new WT_Date($date_match);
							if ($date->isOK() && $date->MaxJD() <= WT_SERVER_JD - 365*($MAX_ALIVE_AGE-30)) {
								return true;
							}
						}
					}
				}
			}
		}
		return false;
	}

	// Find the highlighted media object for an individual
	// 1. Ignore all media objects that are not displayable because of Privacy rules
	// 2. Ignore all media objects with the Highlight option set to "N"
	// 3. Pick the first media object that matches these criteria, in order of preference:
	//    (a) Level 1 object with the Highlight option set to "Y"
	//    (b) Level 1 object with the Highlight option missing or set to other than "Y" or "N"
	//    (c) Level 2 or higher object with the Highlight option set to "Y"
	function findHighlightedMedia() {
		$objectA = null;
		$objectB = null;
		$objectC = null;

		// Iterate over all of the media items for the person
		preg_match_all('/\n(\d) OBJE @(' . WT_REGEX_XREF . ')@/', $this->getGedcom(), $matches, PREG_SET_ORDER);
		foreach ($matches as $match) {
			$media = WT_Media::getInstance($match[2]);
			if (!$media || !$media->canShow() || $media->isExternal()) {
				continue;
			}
			$level = $match[1];
			$prim=$media->isPrimary();
			if ($prim=='N') {
				continue;
			}
			if ($level == 1) {
				if ($prim == 'Y') {
					if (empty($objectA)) {
						$objectA = $media;
					}
				} else {
					if (empty($objectB)) {
						$objectB = $media;
					}
				}
			} else {
				if ($prim == 'Y') {
					if (empty($objectC)) {
						$objectC = $media;
					}
				}
			}
		}

		if ($objectA) return $objectA;
		if ($objectB) return $objectB;
		if ($objectC) return $objectC;

		return null;
	}

	// Display the prefered image for this individual.
	// Use an icon if no image is available.
	public function displayImage() {
		global $USE_SILHOUETTE;

		$media = $this->findHighlightedMedia();
		if ($media) {
			// Thumbnail exists - use it.
			return $media->displayImage();
		} elseif ($USE_SILHOUETTE) {
			// No thumbnail exists - use an icon
			return '<i class="icon-silhouette-' . $this->getSex() . '"></i>';
		} else {
			return '';
		}
	}

	/**
	* get birth date
	* @return WT_Date the birth date
	*/
	function getBirthDate() {
		if (is_null($this->_getBirthDate)) {
			if ($this->canShow()) {
				foreach ($this->getAllBirthDates() as $date) {
					if ($date->isOK()) {
						$this->_getBirthDate=$date;
						break;
					}
				}
				if (is_null($this->_getBirthDate)) {
					$this->_getBirthDate=new WT_Date('');
				}
			} else {
				$this->_getBirthDate=new WT_Date("(".WT_I18N::translate('Private').")");
			}
		}
		return $this->_getBirthDate;
	}

	/**
	* get the birth place
	* @return string
	*/
	function getBirthPlace() {
		if (is_null($this->_getBirthPlace)) {
			if ($this->canShow()) {
				foreach ($this->getAllBirthPlaces() as $place) {
					if ($place) {
						$this->_getBirthPlace=$place;
						break;
					}
				}
				if (is_null($this->_getBirthPlace)) {
					$this->_getBirthPlace='';
				}
			} else {
				$this->_getBirthPlace=WT_I18N::translate('Private');
			}
		}
		return $this->_getBirthPlace;
	}

	/**
	* get the Census birth place (Town and County (reversed))
	* @return string
	*/
	function getCensBirthPlace() {
		if (is_null($this->_getBirthPlace)) {
			if ($this->canShow()) {
				foreach ($this->getAllBirthPlaces() as $place) {
					if ($place) {
						$this->_getBirthPlace=$place;
						break;
					}
				}
				if (is_null($this->_getBirthPlace)) {
					$this->_getBirthPlace='';
				}
			} else {
				$this->_getBirthPlace=WT_I18N::translate('Private');
			}
		}
		$censbirthplace = $this->_getBirthPlace;
		$censbirthplace = explode(', ', $censbirthplace);
		$censbirthplace = array_reverse($censbirthplace);
		$censbirthplace = array_slice($censbirthplace, 1);
		$censbirthplace = array_slice($censbirthplace, 0, 2);
		$censbirthplace = implode(', ', $censbirthplace);
		return $censbirthplace;
	}

	/**
	* get the birth year
	* @return string the year of birth
	*/
	function getBirthYear() {
		return $this->getBirthDate()->MinDate()->Format('%Y');
	}

	/**
	* get death date
	* @return WT_Date the death date in the GEDCOM format of '1 JAN 2006'
	*/
	function getDeathDate($estimate = true) {
		if (is_null($this->_getDeathDate)) {
			if ($this->canShow()) {
				foreach ($this->getAllDeathDates() as $date) {
					if ($date->isOK()) {
						$this->_getDeathDate=$date;
						break;
					}
				}
				if (is_null($this->_getDeathDate)) {
					$this->_getDeathDate=new WT_Date('');
				}
			} else {
				$this->_getDeathDate=new WT_Date("(".WT_I18N::translate('Private').")");
			}
		}
		return $this->_getDeathDate;
	}

	/**
	* get the death place
	* @return string
	*/
	function getDeathPlace() {
		if (is_null($this->_getDeathPlace)) {
			if ($this->canShow()) {
				foreach ($this->getAllDeathPlaces() as $place) {
					if ($place) {
						$this->_getDeathPlace=$place;
						break;
					}
				}
				if (is_null($this->_getDeathPlace)) {
					$this->_getDeathPlace='';
				}
			} else {
				$this->_getDeathPlace=WT_I18N::translate('Private');
			}
		}
		return $this->_getDeathPlace;
	}

	/**
	* get the death year
	* @return string the year of death
	*/
	function getDeathYear() {
		return $this->getDeathDate()->MinDate()->Format('%Y');
	}

	/**
	* get the birth and death years
	* @return string
	*/
	function getBirthDeathYears($age_at_death=true, $classname='details1') {
		if (!$this->getBirthYear()) {
			return '';
		}
		$tmp = '<span dir="ltr" title="'.strip_tags($this->getBirthDate()->Display()).'">'.$this->getBirthYear();
			if (strip_tags($this->getDeathYear()) =='') { $tmp .= '</span>'; } else { $tmp .= '-</span>'; } 		
		$tmp .= '<span title="'.strip_tags($this->getDeathDate()->Display()).'">'.$this->getDeathYear().'</span>';
		// display age only for exact dates (empty date qualifier)
		if ($age_at_death
			&& $this->getBirthYear() && empty($this->getBirthDate()->qual1)
			&& $this->getDeathYear() && empty($this->getDeathDate()->qual1)) {
			$age = get_age_at_event(WT_Date::GetAgeGedcom($this->getBirthDate(), $this->getDeathDate()), false);
			if (!empty($age)) {
				$tmp .= '<span class="age"> ('.WT_I18N::translate('Age').' '.$age.')</span>';
			}
		}
		if ($classname) {
			return '<span class="'.$classname.'">'.$tmp.'</span>';
		}
		return $tmp;
	}

	// Get the range of years in which a person lived.  e.g. “1870–”, “1870–1920”, “–1920”.
	// Provide the full date using a tooltip.
	// For consistent layout in charts, etc., show just a “–” when no dates are known.
	// Note that this is a (non-breaking) en-dash, and not a hyphen.
	public function getLifeSpan() {
		return
			/* I18N: A range of years, e.g. “1870–”, “1870–1920”, “–1920” */ WT_I18N::translate(
				'%1$s–%2$s',
				'<span title="'.strip_tags($this->getBirthDate()->Display()).'">'.$this->getBirthDate()->MinDate()->Format('%Y').'</span>',
				'<span title="'.strip_tags($this->getDeathDate()->Display()).'">'.$this->getDeathDate()->MinDate()->Format('%Y').'</span>'
			);
	}

	// Get all the dates/places for births/deaths - for the INDI lists
	function getAllBirthDates() {
		if (is_null($this->_getAllBirthDates)) {
			if ($this->canShow()) {
				foreach (explode('|', WT_EVENTS_BIRT) as $event) {
					if ($this->_getAllBirthDates=$this->getAllEventDates($event)) {
						break;
					}
				}
			} else {
				$this->_getAllBirthDates=array();
			}
		}
		return $this->_getAllBirthDates;
	}
	function getAllBirthPlaces() {
		if (is_null($this->_getAllBirthPlaces)) {
			if ($this->canShow()) {
				foreach (explode('|', WT_EVENTS_BIRT) as $event) {
					if ($this->_getAllBirthPlaces=$this->getAllEventPlaces($event)) {
						break;
					}
				}
			} else {
				$this->_getAllBirthPlaces=array();
			}
		}
		return $this->_getAllBirthPlaces;
	}
	function getAllDeathDates() {
		if (is_null($this->_getAllDeathDates)) {
			if ($this->canShow()) {
				foreach (explode('|', WT_EVENTS_DEAT) as $event) {
					if ($this->_getAllDeathDates=$this->getAllEventDates($event)) {
						break;
					}
				}
			} else {
				$this->_getAllDeathDates=array();
			}
		}
		return $this->_getAllDeathDates;
	}
	function getAllDeathPlaces() {
		if (is_null($this->_getAllDeathPlaces)) {
			if ($this->canShow()) {
				foreach (explode('|', WT_EVENTS_DEAT) as $event) {
					if ($this->_getAllDeathPlaces=$this->getAllEventPlaces($event)) {
						break;
					}
				}
			} else {
				$this->_getAllDeathPlaces=array();
			}
		}
		return $this->_getAllDeathPlaces;
	}

	// Generate an estimate for birth/death dates, based on dates of parents/children/spouses
	function getEstimatedBirthDate() {
		if (is_null($this->_getEstimatedBirthDate)) {
			foreach ($this->getAllBirthDates() as $date) {
				if ($date->isOK()) {
					$this->_getEstimatedBirthDate=$date;
					break;
				}
			}
			if (is_null($this->_getEstimatedBirthDate)) {
				$min=array();
				$max=array();
				$tmp=$this->getDeathDate();
				if ($tmp->MinJD()) {
					global $MAX_ALIVE_AGE;
					$min[]=$tmp->MinJD()-$MAX_ALIVE_AGE*365;
					$max[]=$tmp->MaxJD();
				}
				foreach ($this->getChildFamilies() as $family) {
					$tmp=$family->getMarriageDate();
					if (is_object($tmp) && $tmp->MinJD()) {
						$min[]=$tmp->MaxJD()-365*1;
						$max[]=$tmp->MinJD()+365*30;
					}
					if ($parent=$family->getHusband()) {
						$tmp=$parent->getBirthDate();
						if (is_object($tmp) && $tmp->MinJD()) {
							$min[]=$tmp->MaxJD()+365*15;
							$max[]=$tmp->MinJD()+365*65;
						}
					}
					if ($parent=$family->getWife()) {
						$tmp=$parent->getBirthDate();
						if (is_object($tmp) && $tmp->MinJD()) {
							$min[]=$tmp->MaxJD()+365*15;
							$max[]=$tmp->MinJD()+365*45;
						}
					}
					foreach ($family->getChildren() as $child) {
						$tmp=$child->getBirthDate();
						if ($tmp->MinJD()) {
							$min[]=$tmp->MaxJD()-365*30;
							$max[]=$tmp->MinJD()+365*30;
						}
					}
				}
				foreach ($this->getSpouseFamilies() as $family) {
					$tmp=$family->getMarriageDate();
					if (is_object($tmp) && $tmp->MinJD()) {
						$min[]=$tmp->MaxJD()-365*45;
						$max[]=$tmp->MinJD()-365*15;
					}
					if ($spouse=$family->getSpouse($this)) {
						$tmp=$spouse->getBirthDate();
						if (is_object($tmp) && $tmp->MinJD()) {
							$min[]=$tmp->MaxJD()-365*25;
							$max[]=$tmp->MinJD()+365*25;
						}
					}
					foreach ($family->getChildren() as $child) {
						$tmp=$child->getBirthDate();
						if ($tmp->MinJD()) {
							$min[]=$tmp->MaxJD()-365*($this->getSex()=='F'?45:65);
							$max[]=$tmp->MinJD()-365*15;
						}
					}
				}
				if ($min && $max) {
					list($y)=WT_Date_Gregorian::JDtoYMD((int)((max($min)+min($max))/2));
					$this->_getEstimatedBirthDate=new WT_Date("EST {$y}");
				} else {
					$this->_getEstimatedBirthDate=new WT_Date(''); // always return a date object
				}
			}
		}
		return $this->_getEstimatedBirthDate;
	}
	function getEstimatedDeathDate() {
		if (is_null($this->_getEstimatedDeathDate)) {
			foreach ($this->getAllDeathDates() as $date) {
				if ($date->isOK()) {
					$this->_getEstimatedDeathDate=$date;
					break;
				}
			}
			if (is_null($this->_getEstimatedDeathDate)) {
				$tmp=$this->getEstimatedBirthDate();
				if ($tmp->MinJD()) {
					global $MAX_ALIVE_AGE;
					$tmp2=$tmp->AddYears($MAX_ALIVE_AGE, 'BEF');
					if ($tmp2->MaxJD()<WT_SERVER_JD) {
						$this->_getEstimatedDeathDate=$tmp2;
					} else {
						$this->_getEstimatedDeathDate=new WT_Date(''); // always return a date object
					}
				} else {
					$this->_getEstimatedDeathDate=new WT_Date(''); // always return a date object
				}
			}
		}
		return $this->_getEstimatedDeathDate;
	}

	/**
	* get the sex
	* @return string  return M, F, or U
	*/
	// Use the un-privatised gedcom record.  We call this function during
	// the privatize-gedcom function, and we are allowed to know this.
	function getSex() {
		if (is_null($this->sex)) {
			if (preg_match('/\n1 SEX ([MF])/', $this->gedcom, $match)) {
				$this->sex=$match[1];
			} else {
				$this->sex='U';
			}
		}
		return $this->sex;
	}

	/**
	* get the person's sex image
	* @return string  <img ...>
	*/
	function getSexImage($size='small', $style='', $title='') {
		return self::sexImage($this->getSex(), $size, $style, $title);
	}

	static function sexImage($sex, $size='small', $style='', $title='') {
		return '<i class="icon-sex_'.strtolower($sex).'_'.($size=='small' ? '9x9' : '15x15').'" title="'.$title.'"></i>';
	}

	function getBoxStyle() {
		$tmp=array('M'=>'','F'=>'F', 'U'=>'NN');
		return 'person_box'.$tmp[$this->getSex()];
	}

	/**
	* set a label for this person
	* The label can be used when building a list of people
	* to display the relationship between this person
	* and the person listed on the page
	* @param string $label
	*/
	function setLabel($label) {
		$this->label = $label;
	}
	/**
	* get the label for this person
	* The label can be used when building a list of people
	* to display the relationship between this person
	* and the person listed on the page
	* @param string $elderdate optional elder sibling birthdate to calculate gap
	* @param int $counter optional children counter
	* @return string
	*/
	function getLabel($elderdate='', $counter=0) {
		$label = '';
		$gap = 0;
		if (is_object($elderdate) && $elderdate->isOK()) {
			$p2 = $this->getBirthDate();
			if ($p2->isOK()) {
				$gap = $p2->MinJD()-$elderdate->MinJD(); // days
				$label .= "<div class=\"elderdate age\">";
				// warning if negative gap : wrong order
				if ($gap<0 && $counter>0) $label .= '<i class="icon-warning"></i> ';
				// warning if gap<6 months
				if ($gap>1 && $gap<180 && $counter>0) $label .= '<i class="icon-warning"></i> ';
				// children with same date means twin
				/**if ($gap==0 && $counter>1) {
					if ($this->getSex()=='M') $label .= WT_I18N::translate('Twin brother');
					else if ($this->getSex()=='F') $label .= WT_I18N::translate('Twin sister');
					else $label .= WT_I18N::translate('Twin');
					}**/
				// gap in years or months
				$gap = round($gap*12/365.25); // months
				if (($gap==12)||($gap==-12)) {
					$label .= WT_I18N::plural('%d year', '%d years', round($gap/12), round($gap/12));
				} elseif ($gap>23 or $gap<-23) {
					$label .= WT_I18N::plural('%d year', '%d years', round($gap/12), round($gap/12));
				} elseif ($gap!=0) {
					$label .= WT_I18N::plural('%d month', '%d months', $gap, $gap);
				}
				$label .= '</div>';
			}
		}
		// I18N: This is an abbreviation for a number.  i.e. #7 means number 7
		if ($counter) $label .= '<div>'.WT_I18N::translate('#%d', $counter).'</div>';
		$label .= $this->label;
		if ($gap!=0 && $counter<1) $label .= '<br>&nbsp;';
		return $label;
	}

	// Get a list of this person's spouse families
	function getSpouseFamilies($access_level=WT_USER_ACCESS_LEVEL) {
		global $SHOW_PRIVATE_RELATIONSHIPS;

		$families = array();
		foreach ($this->getFacts('FAMS', $access_level) as $fact) {
			$family = $fact->getTarget();
			if ($family && ($SHOW_PRIVATE_RELATIONSHIPS || $family->canShow($access_level))) {
				$families[] = $family;
			}
		}
		return $families;
	}

	/**
	* get the current spouse of this person
	* The current spouse is defined as the spouse from the latest family.
	* The latest family is defined as the last family in the GEDCOM record
	* @return Person  this person's spouse
	*/
	function getCurrentSpouse() {
		$tmp=$this->getSpouseFamilies();
		$family = end($tmp);
		if ($family) {
			return $family->getSpouse($this);
		} else {
			return null;
		}
	}

	// Get a count of the children for this individual
	function getNumberOfChildren() {
		if (preg_match('/\n1 NCHI (\d+)(?:\n|$)/', $this->getGedcom(), $match)) {
			return $match[1];
		} else {
			$children=array();
			foreach ($this->getSpouseFamilies() as $fam) {
				foreach ($fam->getChildren() as $child) {
					$children[$child->getXref()]=true;
				}
			}
			return count($children);
		}
	}

	// Get a list of this person's child families (i.e. their parents)
	function getChildFamilies($access_level=WT_USER_ACCESS_LEVEL) {
		global $SHOW_PRIVATE_RELATIONSHIPS;

		$families = array();
		foreach ($this->getFacts('FAMC', $access_level) as $fact) {
			$family = $fact->getTarget();
			if ($family && ($SHOW_PRIVATE_RELATIONSHIPS || $family->canShow($access_level))) {
				$families[] = $family;
			}
		}
		return $families;
	}

	/**
	* get primary family with parents
	* @return Family object
	*/
	function getPrimaryChildFamily() {
		$families=$this->getChildFamilies();
		switch (count($families)) {
		case 0:
			return null;
		case 1:
			return reset($families);
		default:
			// If there is more than one FAMC record, choose the preferred parents:
			// a) records with '2 _PRIMARY'
			foreach ($families as $famid=>$fam) {
				if (preg_match("/\n1 FAMC @{$famid}@\n(?:[2-9].*\n)*(?:2 _PRIMARY Y)/", $this->getGedcom())) {
					return $fam;
				}
			}
			// b) records with '2 PEDI birt'
			foreach ($families as $famid=>$fam) {
				if (preg_match("/\n1 FAMC @{$famid}@\n(?:[2-9].*\n)*(?:2 PEDI birth)/", $this->getGedcom())) {
					return $fam;
				}
			}
			// c) records with no '2 PEDI'
			foreach ($families as $famid=>$fam) {
				if (!preg_match("/\n1 FAMC @{$famid}@\n(?:[2-9].*\n)*(?:2 PEDI)/", $this->getGedcom())) {
					return $fam;
				}
			}
			// d) any record
			return reset($families);
		}
	}

	// Get a list of step-parent families
	function getChildStepFamilies() {
		$step_families=array();
		$families=$this->getChildFamilies();
		foreach ($families as $family) {
			$father=$family->getHusband();
			if ($father) {
				foreach ($father->getSpouseFamilies() as $step_family) {
					if (!in_array($step_family, $families, true)) {
						$step_families[]=$step_family;
					}
				}
			}
			$mother=$family->getWife();
			if ($mother) {
				foreach ($mother->getSpouseFamilies() as $step_family) {
					if (!in_array($step_family, $families, true)) {
						$step_families[]=$step_family;
					}
				}
			}
		}
		return $step_families;
	}

	// Get a list of step-child families
	function getSpouseStepFamilies() {
		$step_families=array();
		$families=$this->getSpouseFamilies();
		foreach ($families as $family) {
			foreach ($family->getSpouse($this)->getSpouseFamilies() as $step_family) {
				if (!in_array($step_family, $families, true)) {
					$step_families[]=$step_family;
				}
			}
		}
		return $step_families;
	}

	// A label for a parental family group
	function getChildFamilyLabel(WT_Family $family) {
		if (preg_match('/\n1 FAMC @'.$family->getXref().'@(?:\n[2-9].*)*\n2 PEDI (.+)/', $this->getGedcom(), $match)) {
			// A specified pedigree
			return WT_Gedcom_Code_Pedi::getChildFamilyLabel($match[1]);
		} else {
			// Default (birth) pedigree
			return WT_Gedcom_Code_Pedi::getChildFamilyLabel('');
		}
	}

	// Create a label for a step family
	function getStepFamilyLabel(WT_Family $family) {
		foreach ($this->getChildFamilies() as $fam) {
			if (!$fam->equals($family)) {
				if ((is_null($fam->getHusband()) || !$fam->getHusband()->equals($family->getHusband())) && (is_null($fam->getWife()) || $fam->getWife()->equals($family->getWife()))) {
					if ($family->getHusband()) {
						if ($family->getWife()->getSex()=='F') {
							return /* I18N: A step-family.  %s is an individual’s name */ WT_I18N::translate('Mother’s family with %s', $family->getHusband()->getFullName());
						} else {
							return /* I18N: A step-family.  %s is an individual’s name */ WT_I18N::translate('Father’s family with %s', $family->getHusband()->getFullName());
						}
					} else {
						if ($family->getWife()->getSex()=='F') {
							return /* I18N: A step-family. */ WT_I18N::translate('Mother’s family with an unknown individual');
						} else {
							return /* I18N: A step-family. */ WT_I18N::translate('Father’s family with an unknown individual');
						}
					}
				} elseif ((is_null($fam->getWife()) || !$fam->getWife()->equals($family->getWife())) && (is_null($fam->getHusband()) || $fam->getHusband()->equals($family->getHusband()))) {
					if ($family->getWife()) {
						if ($family->getHusband()->getSex()=='F') {
							return /* I18N: A step-family.  %s is an individual’s name */ WT_I18N::translate('Mother’s family with %s', $family->getWife()->getFullName());
						} else {
							return /* I18N: A step-family.  %s is an individual’s name */ WT_I18N::translate('Father’s family with %s', $family->getWife()->getFullName());
						}
					} else {
						if ($family->getHusband()->getSex()=='F') {
							return /* I18N: A step-family. */ WT_I18N::translate('Mother’s family with an unknown individual');
						} else {
							return /* I18N: A step-family. */ WT_I18N::translate('Father’s family with an unknown individual');
						}
					}
				} elseif ($family->getWife()==$fam->getWife() && $family->getHusband()==$fam->getHusband() || $family->getWife()==$fam->getHusband() && $family->getHusband()==$fam->getWife()) {
					// Same parents - but a different family record.
					return WT_I18N::translate('Family with parents');
				}
			}
		}
		// It should not be possible to get here
		throw new Exception('Invalid family in WT_Individual::getStepFamilyLabel(' . $family . ')');
	}

	// TODO - this function doesn't belong in this class
	function getSpouseFamilyLabel(WT_Family $family) {
		return /* I18N: %s is the spouse name */ WT_I18N::translate('Family with %s', $family->getSpouse($this)->getFullName());
	}

	/**
	* get primary parents names for this person
	* @param string $classname optional css class
	* @param string $display optional css style display
	* @return string a div block with father & mother names
	*/
	function getPrimaryParentsNames($classname='', $display='') {
		$fam = $this->getPrimaryChildFamily();
		if (!$fam) return '';
		$txt = '<div';
		if ($classname) $txt .= " class=\"$classname\"";
		if ($display) $txt .= " style=\"display:$display\"";
		$txt .= '>';
		$husb = $fam->getHusband();
		if ($husb) {
			// Temporarily reset the 'prefered' display name, as we always
			// want the default name, not the one selected for display on the indilist.
			$primary=$husb->getPrimaryName();
			$husb->setPrimaryName(null);
			$txt .= /* I18N: %s is the name of an individual’s father */ WT_I18N::translate('Father: %s', $husb->getFullName()).'<br>';
			$husb->setPrimaryName($primary);
		}
		$wife = $fam->getWife();
		if ($wife) {
			// Temporarily reset the 'prefered' display name, as we always
			// want the default name, not the one selected for display on the indilist.
			$primary=$wife->getPrimaryName();
			$wife->setPrimaryName(null);
			$txt .= /* I18N: %s is the name of an individual’s mother */ WT_I18N::translate('Mother: %s', $wife->getFullName());
			$wife->setPrimaryName($primary);
		}
		$txt .= '</div>';
		return $txt;
	}

	// If this object has no name, what do we call it?
	function getFallBackName() {
		return '@P.N. /@N.N./';
	}

	// Convert a name record into 'full' and 'sort' versions.
	// Use the NAME field to generate the 'full' version, as the
	// gedcom spec says that this is the person's name, as they would write it.
	// Use the SURN field to generate the sortable names.  Note that this field
	// may also be used for the 'true' surname, perhaps spelt differently to that
	// recorded in the NAME field. e.g.
	//
	// 1 NAME Robert /de Gliderow/
	// 2 GIVN Robert
	// 2 SPFX de
	// 2 SURN CLITHEROW
	// 2 NICK The Bald
	//
	// full=>'Robert de Gliderow 'The Bald''
	// sort=>'CLITHEROW, ROBERT'
	//
	// Handle multiple surnames, either as;
	// 1 NAME Carlos /Vasquez/ y /Sante/
	// or
	// 1 NAME Carlos /Vasquez y Sante/
	// 2 GIVN Carlos
	// 2 SURN Vasquez,Sante
	protected function _addName($type, $full, $gedcom) {
		global $UNKNOWN_NN, $UNKNOWN_PN;

		////////////////////////////////////////////////////////////////////////////
		// Extract the structured name parts - use for "sortable" names and indexes
		////////////////////////////////////////////////////////////////////////////

		$sublevel=1+(int)$gedcom[0];
		$NPFX=preg_match("/\n{$sublevel} NPFX (.+)/", $gedcom, $match) ? $match[1] : '';
		$GIVN=preg_match("/\n{$sublevel} GIVN (.+)/", $gedcom, $match) ? $match[1] : '';
		$SURN=preg_match("/\n{$sublevel} SURN (.+)/", $gedcom, $match) ? $match[1] : '';
		$NSFX=preg_match("/\n{$sublevel} NSFX (.+)/", $gedcom, $match) ? $match[1] : '';
		$NICK=preg_match("/\n{$sublevel} NICK (.+)/", $gedcom, $match) ? $match[1] : '';

		// SURN is an comma-separated list of surnames...
		if ($SURN) {
			$SURNS=preg_split('/ *, */', $SURN);
		} else {
			$SURNS=array();
		}
		// ...so is GIVN - but nobody uses it like that
		$GIVN=str_replace('/ *, */', ' ', $GIVN);

		////////////////////////////////////////////////////////////////////////////
		// Extract the components from NAME - use for the "full" names
		////////////////////////////////////////////////////////////////////////////

		// Fix bad slashes.  e.g. 'John/Smith' => 'John/Smith/'
		if (substr_count($full, '/')%2==1) {
			$full=$full.'/';
		} else {
			$full=$full;
		}

		// GEDCOM uses "//" to indicate an unknown surname
		$full=preg_replace('/\/\//', '/@N.N./', $full);

		// Extract the surname.
		// Note, there may be multiple surnames, e.g. Jean /Vasquez/ y /Cortes/
		if (preg_match('/\/.*\//', $full, $match)) {
			$surname=str_replace('/', '', $match[0]);
		} else {
			$surname='';
		}

		// If we don't have a SURN record, extract it from the NAME
		if (!$SURNS) {
			if (preg_match_all('/\/([^\/]*)\//', $full, $matches)) {
				// There can be many surnames, each wrapped with '/'
				$SURNS=$matches[1];
				foreach ($SURNS as $n=>$SURN) {
					// Remove surname prefixes, such as "van de ", "d'" and "'t " (lower case only)
					$SURNS[$n]=preg_replace('/^(?:[a-z]+ |[a-z]+\' ?|\'[a-z]+ )+/', '', $SURN);
				}
			} else {
				// It is valid not to have a surname at all
				$SURNS=array('');
			}
		}

		// If we don't have a GIVN record, extract it from the NAME
		if (!$GIVN) {
			$GIVN=preg_replace(
				array(
					'/ ?\/.*\/ ?/', // remove surname
					'/ ?".+"/',     // remove nickname
					'/ {2,}/',      // multiple spaces, caused by the above
					'/^ | $/',      // leading/trailing spaces, caused by the above
				),
				array(
					' ',
					' ',
					' ',
					'',
				),
				$full
			);
		}

		// Add placeholder for unknown given name
		if (!$GIVN) {
			$GIVN='@P.N.';
			$pos=strpos($full, '/');
			$full=substr($full, 0, $pos).'@P.N. '.substr($full, $pos);
		}

		// The NPFX field might be present, but not appear in the NAME
		if ($NPFX && strpos($full, "$NPFX ")!==0) {
			$full="$NPFX $full";
		}

		// The NSFX field might be present, but not appear in the NAME
		if ($NSFX && strrpos($full, " $NSFX")!==strlen($full)-strlen(" $NSFX")) {
			$full="$full $NSFX";
		}

		// GEDCOM nicknames should be specificied in a NICK field, or in the
		// NAME filed, surrounded by ASCII quotes (or both).
		if ($NICK) {
			// NICK field found.  Add localised quotation marks.

			// GREG 28/Jan/12 - these localised quotation marks apparantly cause problems with LTR names on RTL
			// pages and vice-versa.  Just use straight ASCII quotes.  Keep the old code, so that we keep the
			// translations.
			if (false) {
				$QNICK=/* I18N: Place a nickname in quotation marks */ WT_I18N::translate('“%s”', $NICK);
			} else {
				$QNICK='"'.$NICK.'"';
			}

			if (preg_match('/(^| |"|«|“|\'|‹|‘|„)'.preg_quote($NICK, '/').'( |"|»|”|\'|›|’|”|$)/', $full)) {
				// NICK present in name.  Localise ASCII quotes (but leave others).
				// GREG 28/Jan/12 - redundant - see comment above.
				// $full=str_replace('"'.$NICK.'"', $QNICK, $full);
			} else {
				// NICK not present in NAME.
				$pos=strpos($full, '/');
				if ($pos===false) {
					// No surname - append it
					$full.=' '.$QNICK;
				} else {
					// Insert before surname
					$full=substr($full, 0, $pos).$QNICK.' '.substr($full, $pos);
				}
			}
		}

		// Remove slashes - they don't get displayed
		// $fullNN keeps the @N.N. placeholders, for the database
		// $full is for display on-screen
		$fullNN=str_replace('/', '', $full);

		// Insert placeholders for any missing/unknown names
		if (strpos($full, '@N.N.')!==false) {
			$full=str_replace('@N.N.', $UNKNOWN_NN, $full);
		}
		if (strpos($full, '@P.N.')!==false) {
			$full=str_replace('@P.N.', $UNKNOWN_PN, $full);
		}
		$full='<span class="NAME" dir="auto" translate="no">'.preg_replace('/\/([^\/]*)\//', '<span class="SURN">$1</span>', htmlspecialchars($full)).'</span>';

		// The standards say you should use a suffix of '*' for preferred name
		$full=preg_replace('/([^ >]*)\*/', '<span class="starredname">\\1</span>', $full);

		// Remove prefered-name indicater - they don't go in the database
		$GIVN  =str_replace('*', '', $GIVN);
		$fullNN=str_replace('*', '', $fullNN);

		foreach ($SURNS AS $SURN) {
			// Scottish 'Mc and Mac ' prefixes both sort under 'Mac'
			if (strcasecmp(substr($SURN, 0, 2), 'Mc')==0) {
				$SURN=substr_replace($SURN, 'Mac', 0, 2);
			} elseif (strcasecmp(substr($SURN, 0, 4), 'Mac ')==0) {
				$SURN=substr_replace($SURN, 'Mac', 0, 4);
			}

			$this->_getAllNames[]=array(
				'type'=>$type,
				'sort'=>$SURN.','.$GIVN,
				'full'=>$full,       // This is used for display
				'fullNN'=>$fullNN,   // This goes into the database
				'surname'=>$surname, // This goes into the database
				'givn'=>$GIVN,       // This goes into the database
				'surn'=>$SURN,       // This goes into the database
			);
		}
	}

	// Get an array of structures containing all the names in the record
	public function getAllNames() {
		return $this->_getAllNames('NAME', 1);
	}

	// Extra info to display when displaying this record in a list of
	// selection items or favorites.
	function format_list_details() {
		return
		$this->format_first_major_fact(WT_EVENTS_BIRT, 1).
		$this->format_first_major_fact(WT_EVENTS_DEAT, 1);
	}

	// create a short name for compact display on charts
	public function getShortName() {
		global $bwidth, $SHOW_HIGHLIGHT_IMAGES, $UNKNOWN_NN, $UNKNOWN_PN;
		// Estimate number of characters that can fit in box. Calulates to 28 characters in webtrees theme, or 34 if no thumbnail used.
		if ($SHOW_HIGHLIGHT_IMAGES) {
			$char = intval(($bwidth-40)/6.5); 
		} else {
			$char = ($bwidth/6.5);
		}
		if ($this->canShowName()) {
			$tmp=$this->getAllNames();
			$givn = $tmp[$this->getPrimaryName()]['givn'];
			$surn = $tmp[$this->getPrimaryName()]['surname'];
			$new_givn = explode(' ', $givn);
			$count_givn = count($new_givn);
			$len_givn = utf8_strlen($givn);
			$len_surn = utf8_strlen($surn);
			$len = $len_givn + $len_surn;
			$i = 1;
			while ($len > $char && $i<=$count_givn) {
				$new_givn[$count_givn-$i] = utf8_substr($new_givn[$count_givn-$i],0,1);
				$givn = implode(' ', $new_givn);
				$len_givn = utf8_strlen($givn);
				$len = $len_givn + $len_surn;
				$i++;
			}
			$max_surn = $char-$i*2;
			if ($len_surn > $max_surn) {
				$surn = substr($surn, 0, $max_surn).'…';
				$len_surn = utf8_strlen($surn);
			}
			$shortname =  str_replace(
				array('@P.N.', '@N.N.'),
				array($UNKNOWN_PN, $UNKNOWN_NN),
				$givn.' '.$surn
			);
			return $shortname;
		} else {
			return WT_I18N::translate('Private');
		}
	}

}
