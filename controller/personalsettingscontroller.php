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
use \OCP\AppFramework\Controller;
use \OCP\IL10N;

use \OCA\ocDownloader\Controller\Lib\Settings;
use \OCA\ocDownloader\Controller\Lib\Tools;

class PersonalSettingsController extends Controller
{
      private $CurrentUID = null;
      private $OCDSettingKeys = Array ('DownloadsFolder', 'TorrentsFolder');
      private $Settings = null;
      private $L10N = null;
      
      public function __construct ($AppName, IRequest $Request, $CurrentUID, IL10N $L10N)
      {
            parent::__construct($AppName, $Request);
            $this->CurrentUID = $CurrentUID;
            
            $this->Settings = new Settings ('personal');
            $this->Settings->SetUID ($this->CurrentUID);
            
            $this->L10N = $L10N;
      }
      
      /**
       * @AdminRequired
       * @NoCSRFRequired
       */
      public function save ()
      {
            $Error = false;
            $Message = '';
            
            if (isset ($_POST['KEY']) && strlen (trim ($_POST['KEY'])) > 0 && isset ($_POST['VAL']) && strlen (trim ($_POST['VAL'])) > 0)
            {
                  $PostKey = str_replace ('OCD', '', $_POST['KEY']);
                  $PostValue = ltrim (trim (str_replace (' ', '\ ', $_POST['VAL'])), '/');
                  
                  if (in_array ($PostKey, $this->OCDSettingKeys))
                  {
                        $this->Settings->SetKey ($PostKey);
                        
                        // Pre-Save process
                        if (strcmp ($PostKey, 'DownloadsFolder') == 0 || strcmp ($PostKey, 'TorrentsFolder') == 0)
                        {
                              // check folder exists, if not create it
                              if (!\OC\Files\Filesystem::is_dir ($PostValue))
                              {
                                    // Create the target file
                                    \OC\Files\Filesystem::mkdir ($PostValue);
                                    
                                    $Message .= $this->L10N->t ('The folder doesn\'t exist. It has been created.');
                              }
                        }
                        
                        if (strlen (trim ($PostValue)) <= 0)
                        {
                              $PostValue = null;
                        }
                        
                        if ($this->Settings->CheckIfKeyExists ())
                        {
                              $this->Settings->UpdateValue ($PostValue);
                        }
                        else
                        {
                              $this->Settings->InsertValue ($PostValue);
                        }
                  }
                  else
                  {
                        $Error = true;
                        $Message = $this->L10N->t ('Unknown field');
                  }
            }
            else
            {
                  $Error = true;
                  $Message = $this->L10N->t ('Undefined field');
            }
            
            die (json_encode (Array ('ERROR' => $Error, 'MESSAGE' => (strlen (trim ($Message)) == 0 ? (string)$this->L10N->t ('Saved') : $Message))));
      }
      
      /**
       * @AdminRequired
       * @NoCSRFRequired
       */
      public function get ()
      {
            if (isset ($_POST['KEY']) && strlen (trim ($_POST['KEY'])) > 0)
            {
                  if (in_array ($_POST['KEY'], $this->OCDSettingKeys))
                  {
                        $this->Settings->SetKey ($_POST['KEY']);
                        $Val = $this->Settings->GetValue ();
                        
                        die (json_encode (Array ('ERROR' => (is_null ($Val) ? true : false), 'VAL' => $Val)));
                  }
                  
                  die (json_encode (Array ('ERROR' => true, 'VAL' => null)));
            }
            
            die (json_encode (Array ('ERROR' => true, 'VAL' => null)));
      }
}