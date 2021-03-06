<?php
/**
 * ownCloud - ocDownloader
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Xavier Beurois <www.sgc-univ.net>
 * @copyright Xavier Beurois 2015
 */
      
namespace OCA\ocDownloader\Controller;

use \OCP\IRequest;
use \OCP\AppFramework\Http\TemplateResponse;
use \OCP\AppFramework\Controller;
use \OCP\Config;
use \OCP\IL10N;

use \OCA\ocDownloader\Controller\Lib\Aria2;
use \OCA\ocDownloader\Controller\Lib\Tools;

class DownloaderQueueController extends Controller
{
      private $UserStorage;
      private $DbType;
      
      public function __construct ($AppName, IRequest $Request, IL10N $L10N)
      {
            parent::__construct($AppName, $Request);
            
            $this->DbType = 0;
            if (strcmp (Config::getSystemValue ('dbtype'), 'pgsql') == 0)
            {
                  $this->DbType = 1;
            }
            
            $this->L10N = $L10N;
      }

      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
      public function get ()
      {
            try
            {
                  if (isset ($_POST['GIDS']) && count ($_POST['GIDS']) > 0)
                  {
                        $Queue = [];
                        
                        foreach ($_POST['GIDS'] as $GID)
                        {
                              $Aria2 = new Aria2();
                              $Status = $Aria2->tellStatus ($GID);
                              $DbStatus = 5; // Error
                              
                              if (!is_null ($Status))
                              {
                                    if (!isset ($Status['error']))
                                    {
                                          $SQL = 'SELECT * FROM `*PREFIX*ocdownloader_queue` WHERE `GID` = ? LIMIT 1';
                                          if ($this->DbType == 1)
                                          {
                                                $SQL = 'SELECT * FROM *PREFIX*ocdownloader_queue WHERE "GID" = ? LIMIT 1';
                                          }
                                          $Query = \OCP\DB::prepare ($SQL);
                                          $Result = $Query->execute (Array ($GID));
                                          $Row = $Result->fetchRow ();
                                          
                                          $Progress = $Status['result']['completedLength'] / $Status['result']['totalLength'];
                                          
                                          $Queue[] = Array (
                                                'GID' => $GID,
                                                'PROGRESSVAL' => round((($Progress) * 100), 2) . '%',
                                                'PROGRESS' => Tools::GetProgressString ($Status['result']['completedLength'], $Status['result']['totalLength']) . (isset ($Status['result']['numSeeders']) && $Progress < 1 ? ' - ' . $this->L10N->t ('Seeders') . ': ' . $Status['result']['numSeeders'] : ''),
                                                'STATUS' => isset ($Status['result']['status']) ? $this->L10N->t (ucfirst ($Status['result']['status'])) . (isset ($Status['result']['numSeeders']) && $Progress == 1 ? ' - ' . $this->L10N->t ('Seeding') : '') : (string)$this->L10N->t ('N/A'),
                                                'SPEED' => isset ($Status['result']['downloadSpeed']) ? ($Status['result']['downloadSpeed'] == 0 ? (isset ($Status['result']['numSeeders']) && $Progress == 1 ? Tools::FormatSizeUnits ($Status['result']['uploadSpeed']) . '/s' : '--') : Tools::FormatSizeUnits ($Status['result']['downloadSpeed']) . '/s') : (string)$this->L10N->t ('N/A'),
                                                'FILENAME' => (strlen ($Row['FILENAME']) > 40 ? substr ($Row['FILENAME'], 0, 40) . '...' : $Row['FILENAME'])
                                          );
                                          
                                          switch (strtolower ($Status['result']['status']))
                                          {
                                                case 'complete':
                                                      $DbStatus = 0;
                                                      break;
                                                case 'active':
                                                      $DbStatus = 1;
                                                      break;
                                                case 'waiting':
                                                      $DbStatus = 2;
                                                      break;
                                                case 'paused':
                                                      $DbStatus = 3;
                                                      break;
                                                case 'removed':
                                                      $DbStatus = 4;
                                                      break;
                                          }
                                          
                                          if ($Row['STATUS'] != $DbStatus)
                                          {
                                                $SQL = 'UPDATE `*PREFIX*ocdownloader_queue` SET `STATUS` = ? WHERE `GID` = ? AND (`STATUS` != ? OR `STATUS` IS NULL)';
                                                if ($this->DbType == 1)
                                                {
                                                      $SQL = 'UPDATE *PREFIX*ocdownloader_queue SET "STATUS" = ? WHERE "GID" = ? AND ("STATUS" != ? OR "STATUS" IS NULL)';
                                                }
                                                
                                                $Query = \OCP\DB::prepare ($SQL);
                                                $Result = $Query->execute (Array (
                                                      $DbStatus,
                                                      $GID,
                                                      4
                                                ));
                                          }
                                    }
                                    else
                                    {
                                          $Queue[] = Array (
                                                'GID' => $GID,
                                                'PROGRESSVAL' => 0,
                                                'PROGRESS' => (string)$this->L10N->t ('Error, GID not found !'),
                                                'STATUS' => (string)$this->L10N->t ('N/A'),
                                                'SPEED' => (string)$this->L10N->t ('N/A')
                                          );
                                    }
                              }
                              else
                              {
                                    $Queue[] = Array (
                                          'GID' => $GID,
                                          'PROGRESSVAL' => 0,
                                          'PROGRESS' => (string)$this->L10N->t ('Returned status is null ! Is Aria2c running as a daemon ?'),
                                          'STATUS' => (string)$this->L10N->t ('N/A'),
                                          'SPEED' => (string)$this->L10N->t ('N/A')
                                    );
                              }
                        }
                        die (json_encode (Array ('ERROR' => false, 'QUEUE' => $Queue)));
                  }
                  else
                  {
                        die (json_encode (Array ('ERROR' => true, 'MESSAGE' => (string)$this->L10N->t ('No GIDS in the download queue'))));
                  }
            }
            catch (Exception $E)
            {
                  die (json_encode (Array ('ERROR' => true, 'MESSAGE' => $E->getMessage ())));
            }
      }
      
      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
      public function remove ()
      {
            try
            {
                  if (isset ($_POST['GID']) && strlen (trim ($_POST['GID'])) > 0)
                  {
                        $Aria2 = new Aria2();
                        $Status = $Aria2->tellStatus ($_POST['GID']);
                        
                        $Remove['result'] = $_POST['GID'];
                        if (!isset ($Status['error']) && strcmp ($Status['result']['status'], 'error') != 0 && strcmp ($Status['result']['status'], 'complete') != 0)
                        {
                              $Remove = $Aria2->remove ($_POST['GID']);
                        }
                        
                        if (strcmp ($Remove['result'], $_POST['GID']) == 0)
                        {
                              $SQL = 'UPDATE `*PREFIX*ocdownloader_queue` SET STATUS = ? WHERE GID = ?';
                              if ($this->DbType == 1)
                              {
                                    $SQL = 'UPDATE *PREFIX*ocdownloader_queue SET "STATUS" = ? WHERE "GID" = ?';
                              }
            
                              $Query = \OCP\DB::prepare ($SQL);
                              $Result = $Query->execute (Array (
                                    4,
                                    $_POST['GID']
                              ));
                              
                              die (json_encode (Array ('ERROR' => false, 'MESSAGE' => (string)$this->L10N->t ('The download has been removed'))));
                        }
                        else
                        {
                              die (json_encode (Array ('ERROR' => true, 'MESSAGE' => (string)$this->L10N->t ('An error occured while removing the download'))));
                        }
                  }
                  else
                  {
                        die (json_encode (Array ('ERROR' => true, 'MESSAGE' => (string)$this->L10N->t ('Bad GID'))));
                  }
            }
            catch (Exception $E)
            {
                  die (json_encode (Array ('ERROR' => true, 'MESSAGE' => $E->getMessage ())));
            }
      }
      
      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
      public function totalremove ()
      {
            try
            {
                  if (isset ($_POST['GID']) && strlen (trim ($_POST['GID'])) > 0)
                  {
                        $Aria2 = new Aria2();
                        $Status = $Aria2->tellStatus ($_POST['GID']);
                        
                        if (!isset ($Status['error']) && strcmp ($Status['result']['status'], 'removed') == 0)
                        {
                              $Remove = $Aria2->removeDownloadResult ($_POST['GID']);
                        }
                        
                        $SQL = 'UPDATE `*PREFIX*ocdownloader_queue` SET IS_DELETED = ? WHERE GID = ?';
                        if ($this->DbType == 1)
                        {
                              $SQL = 'UPDATE *PREFIX*ocdownloader_queue SET "IS_DELETED" = ? WHERE "GID" = ?';
                        }
      
                        $Query = \OCP\DB::prepare ($SQL);
                        $Result = $Query->execute (Array (
                              1,
                              $_POST['GID']
                        ));
                        
                        die (json_encode (Array ('ERROR' => false, 'MESSAGE' => (string)$this->L10N->t ('The download has been totally removed'))));
                  }
                  else
                  {
                        die (json_encode (Array ('ERROR' => true, 'MESSAGE' => (string)$this->L10N->t ('Bad GID'))));
                  }
            }
            catch (Exception $E)
            {
                  die (json_encode (Array ('ERROR' => true, 'MESSAGE' => $E->getMessage ())));
            }
      }
      
      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
      public function pause ()
      {
            try
            {
                  if (isset ($_POST['GID']) && strlen (trim ($_POST['GID'])) > 0)
                  {
                        $Aria2 = new Aria2();
                        $Status = $Aria2->tellStatus ($_POST['GID']);
                        
                        $Pause['result'] = $_POST['GID'];
                        if (!isset ($Status['error']) && strcmp ($Status['result']['status'], 'error') != 0 && strcmp ($Status['result']['status'], 'complete') != 0  && strcmp ($Status['result']['status'], 'active') == 0)
                        {
                              $Pause = $Aria2->pause ($_POST['GID']);
                        }
                        
                        if (strcmp ($Pause['result'], $_POST['GID']) == 0)
                        {
                              $SQL = 'UPDATE `*PREFIX*ocdownloader_queue` SET STATUS = ? WHERE GID = ?';
                              if ($this->DbType == 1)
                              {
                                    $SQL = 'UPDATE *PREFIX*ocdownloader_queue SET "STATUS" = ? WHERE "GID" = ?';
                              }
            
                              $Query = \OCP\DB::prepare ($SQL);
                              $Result = $Query->execute (Array (
                                    3,
                                    $_POST['GID']
                              ));
                              
                              die (json_encode (Array ('ERROR' => false, 'MESSAGE' => (string)$this->L10N->t ('The download has been paused'))));
                        }
                        else
                        {
                              die (json_encode (Array ('ERROR' => true, 'MESSAGE' => (string)$this->L10N->t ('An error occured while pausing the download'))));
                        }
                  }
                  else
                  {
                        die (json_encode (Array ('ERROR' => true, 'MESSAGE' => (string)$this->L10N->t ('Bad GID'))));
                  }
            }
            catch (Exception $E)
            {
                  die (json_encode (Array ('ERROR' => true, 'MESSAGE' => $E->getMessage ())));
            }
      }
      
      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
      public function unpause ()
      {
            try
            {
                  if (isset ($_POST['GID']) && strlen (trim ($_POST['GID'])) > 0)
                  {
                        $Aria2 = new Aria2();
                        $Status = $Aria2->tellStatus ($_POST['GID']);
                        
                        $UnPause['result'] = $_POST['GID'];
                        if (!isset ($Status['error']) && strcmp ($Status['result']['status'], 'error') != 0 && strcmp ($Status['result']['status'], 'complete') != 0  && strcmp ($Status['result']['status'], 'paused') == 0)
                        {
                              $UnPause = $Aria2->unpause ($_POST['GID']);
                        }
                        
                        if (strcmp ($UnPause['result'], $_POST['GID']) == 0)
                        {
                              $SQL = 'UPDATE `*PREFIX*ocdownloader_queue` SET STATUS = ? WHERE GID = ?';
                              if ($this->DbType == 1)
                              {
                                    $SQL = 'UPDATE *PREFIX*ocdownloader_queue SET "STATUS" = ? WHERE "GID" = ?';
                              }
            
                              $Query = \OCP\DB::prepare ($SQL);
                              $Result = $Query->execute (Array (
                                    1,
                                    $_POST['GID']
                              ));
                              
                              die (json_encode (Array ('ERROR' => false, 'MESSAGE' => (string)$this->L10N->t ('The download has been unpaused'))));
                        }
                        else
                        {
                              die (json_encode (Array ('ERROR' => true, 'MESSAGE' => (string)$this->L10N->t ('An error occured while unpausing the download'))));
                        }
                  }
                  else
                  {
                        die (json_encode (Array ('ERROR' => true, 'MESSAGE' => (string)$this->L10N->t ('Bad GID'))));
                  }
            }
            catch (Exception $E)
            {
                  die (json_encode (Array ('ERROR' => true, 'MESSAGE' => $E->getMessage ())));
            }
      }
      
      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
      public function clean ()
      {
            try
            {
                  if (isset ($_POST['GIDS']) && count ($_POST['GIDS']) > 0)
                  {
                        $Queue = Array ();
                        
                        foreach ($_POST['GIDS'] as $GID)
                        {
                              $Aria2 = new Aria2();
                              $Status = $Aria2->tellStatus ($GID);
                              
                              $Remove['result'] = $GID;
                              if (!isset ($Status['error']) && strcmp ($Status['result']['status'], 'error') != 0 && strcmp ($Status['result']['status'], 'complete') != 0)
                              {
                                    $Remove = $Aria2->remove ($GID);
                              }
                              
                              if (strcmp ($Remove['result'], $GID) == 0)
                              {
                                    $SQL = 'UPDATE `*PREFIX*ocdownloader_queue` SET STATUS = ? WHERE GID = ?';
                                    if ($this->DbType == 1)
                                    {
                                          $SQL = 'UPDATE *PREFIX*ocdownloader_queue SET "STATUS" = ? WHERE "GID" = ?';
                                    }
                  
                                    $Query = \OCP\DB::prepare ($SQL);
                                    $Result = $Query->execute (Array (
                                          4,
                                          $GID
                                    ));
                              }
                              
                              $Queue[] = Array (
                                    'GID' => $GID
                              );
                        }
                        
                        die (json_encode (Array ('ERROR' => false, 'MESSAGE' => (string)$this->L10N->t ('All downloads have been removed'), 'QUEUE' => $Queue)));
                  }
                  else
                  {
                        die (json_encode (Array ('ERROR' => true, 'MESSAGE' => (string)$this->L10N->t ('No GIDS in the download queue'))));
                  }
            }
            catch (Exception $E)
            {
                  die (json_encode (Array ('ERROR' => true, 'MESSAGE' => $E->getMessage ())));
            }
      }
      
      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
      public function totalclean ()
      {
            try
            {
                  if (isset ($_POST['GIDS']) && count ($_POST['GIDS']) > 0)
                  {
                        $Queue = Array ();
                        
                        foreach ($_POST['GIDS'] as $GID)
                        {
                              $Aria2 = new Aria2();
                              $Status = $Aria2->tellStatus ($GID);
                              
                              if (!isset ($Status['error']) && strcmp ($Status['result']['status'], 'removed') == 0)
                              {
                                    $Remove = $Aria2->removeDownloadResult ($GID);
                              }
                              
                              $SQL = 'UPDATE `*PREFIX*ocdownloader_queue` SET `IS_DELETED` = ? WHERE GID = ?';
                              if ($this->DbType == 1)
                              {
                                    $SQL = 'UPDATE *PREFIX*ocdownloader_queue SET "IS_DELETED" = ? WHERE "GID" = ?';
                              }
            
                              $Query = \OCP\DB::prepare ($SQL);
                              $Result = $Query->execute (Array (
                                    1,
                                    $GID
                              ));
                              
                              $Queue[] = Array (
                                    'GID' => $GID
                              );
                        }
                        
                        die (json_encode (Array ('ERROR' => false, 'MESSAGE' => (string)$this->L10N->t ('The download has been totally removed'), 'QUEUE' => $Queue)));
                  }
                  else
                  {
                        die (json_encode (Array ('ERROR' => true, 'MESSAGE' => (string)$this->L10N->t ('Bad GID'))));
                  }
            }
            catch (Exception $E)
            {
                  die (json_encode (Array ('ERROR' => true, 'MESSAGE' => $E->getMessage ())));
            }
      }
}
?>