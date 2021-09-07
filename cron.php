#!/usr/local/bin/php
<?php
// Facebook Multi Page/Group Poster v3
// Created by Novartis (Safwan)

chdir( dirname( __FILE__ ) );
error_reporting( 0 );
$curTime = time();
$curTimeString = date( 'd-M-Y G:i', $curTime );

if ( !file_exists( 'params.php' ) )
    echoDie( "$curTimeString: No Prms\n", 1 );
else
    require_once( 'params.php' );
require_once( 'functions.php' );

//DB existence check, die otherwise
if ( !file_exists( $dbName . '-settings.db' ) || !file_exists( $dbName . '-logs.db' ) || !file_exists( $dbName . '-crons.db' ) || !file_exists( $dbName . '-users.db' ) )
    echoDie( "$curTimeString: No DBs\n", 1);

readSettings();
if ( !$adminOptions[ 'useCron' ] )
    echoDie( "$curTimeString: CRON disabled\n", 1 );

if ( ( $curTime - $adminOptions[ 'lastCronRun' ] + 5 ) < ( $adminOptions[ 'cronDelay' ] * 60 ) )
    echoDie( "$curTimeString: Too Early Run\n", 1 );

$adminOptions[ 'lastCronExecution' ] = $curTime;
saveAdminOptions();

if ( ($db = new PDO( 'sqlite:' . $dbName . '-crons.db' )) && ($db2 = new PDO( 'sqlite:' . $dbName . '-users.db' )) && 
      ($db3 = new PDO( 'sqlite:' . $dbName . '-logs.db' )) ) {
    global $curTime, $curTimeString, $adminOptions, $userOptions, $userId, $config; //tocheck
    $totalPosts = 0;
    $totalPosts2 = 0;
	$batchPosts = 40;
	$batchParams = '[';
    $oldRecDate = time() - 84600 * 7;    
	$statement = $db3->prepare("DELETE FROM Logs WHERE date < " . $oldRecDate );
    if ($statement) {
        $statement->execute();
    } else {
        die($failImg . " SLog Old Records Deletion failed!");
    }
	selectPosts:
	$failedPosts = 0;
    $statement = $db->prepare( "SELECT * FROM Crons WHERE date <= " . $curTime . " ORDER BY date ASC" );
    if ( $statement ) {
        $statement->execute();
    } else {
        echoDie( "$curTimeString: DB Fail\n", 1 );
    }
    $tempData = $statement->fetchAll();
    if ( !count( $tempData ) )
        die();
    $totalPosts2 += count( $tempData );
    $adminOptions[ 'lastCronRun' ] = $curTime;
    saveAdminOptions();
    $db->beginTransaction();
    
    foreach ( $tempData as $v ) {
        $p      = explode( '|', $v[ 'params' ] );
        $params = array();
        foreach ( $p as $param ) {
            list( $paramName, $paramValue ) = explode( ',', $param );
            $params[ $paramName ] = urldecode( $paramValue );
        }
        $username = substr( $v[ 'user' ], strpos( $v[ 'user' ], "(" ) + 1, -1 );            
        $statement2 = $db2->prepare( "SELECT * FROM FB WHERE username = \"" . $username . "\"" );
        if ( $statement2 ) {
            $statement2->execute();
            $tempData2 = $statement2->fetchAll();
            if ( count( $tempData2 ) ) {
            	$userOptions  = readOptions( $tempData2[ 0 ][ 'useroptions' ] );
        		$userOptions  = checkUserOptions( $userOptions );
        		$userId = $username;
        		if ( $userOptions[ 'userDisabled' ] ) {
					echoDie( "$curTimeString: User Disabled/NotApproved ($username)\n" );
					++$failedPosts;
					goto PostDel; //Is delete acceptable in this case? Or defer would be better?
				}
				if ( $userOptions[ 'autoPause' ] ) {
					if (($userOptions['totalCronPosts'] >= $userOptions['autoPauseAfter']) && (($curTime - $userOptions['lastCronPostTime'])< ( $userOptions[ 'autoPauseDelay' ] * 60 ))) {
						$newSchedule = $curTime + (( $userOptions[ 'autoPauseDelay' ] * 60 )- ($curTime - $userOptions['lastCronPostTime']));
						$statement = $db->prepare( "UPDATE Crons SET date=\"$newSchedule\" WHERE status = \"" . $v[ 'status' ] . "\"" );
				        if ( $statement ) {
				            $statement->execute();
				            echoDie( "$curTimeString: Post Defer (AutoPause) till " . date( 'd-M-Y G:i', $newSchedule ) . " for " . $v[ 'status' ] . " ($username)\n" );
				        } else {
				            echoDie( "$curTimeString: Defer Fail for " . $v[ 'status' ] . " ($username)\n" );
				        }
				        ++$failedPosts;
				        continue;
					} elseif ( !isset( $userPosts[$username] ) || ( $userPosts[$username] <= $adminOptions[ 'maxCronPosts' ] - 1 ) ) {
						if (($curTime - $userOptions['lastCronPostTime']) >= ( $userOptions[ 'autoPauseDelay' ] * 60 - 10 ) )
							$userOptions['totalCronPosts'] = 0;
						$userOptions['lastCronPostTime'] = time();
						++$userOptions['totalCronPosts'];
						saveUserOptions();							
					}
				}						
            	if ( !array_key_exists( "access_token", $params ) || !$params[ "access_token" ] ) {
                	$params[ "access_token" ] = $tempData2[ 0 ][ 'usertoken' ];
                }
            } else {
                echoDie( "$curTimeString: User Gone $username\n" );
                ++$failedPosts;
                goto PostDel;
            }
        } else {
            echoDie( "$curTimeString: User DB Fail\n" );
            ++$failedPosts;
            goto PostDel;
        }
        if ( !isset( $userPosts[$username] ) || ( $userPosts[$username] <= $adminOptions[ 'maxCronPosts' ] - 1 ) && ( $totalPosts <= $batchPosts ) ) {
        	if ($params["postType"] == "M") {
        		require_once( "src/facebook.php" );
				$fb = new Facebook( $config );
				for ($i=1;$i<=7;++$i) {
					if (array_key_exists("url$i",$params)) {
						try {
							$ret = $fb->api( '/' . $GLOBALS[ '__FBAPI__' ] . '/' . $params["targetID"] . '/' . "photos", 'POST', array("access_token"=>$params[ "access_token" ],"caption"=>"","url"=>$params["url$i"],"published"=>"false") );							
							$params["attached_media[" . ($i-1) . "]"] = "{'media_fbid':'" . $ret[ 'id' ] . "'}";
							echoDie( "$curTimeString: Photo $i uploaded for multi-image post " . $v["user"] . "\n" );
						} catch ( Exception $e ) {
				        	echoDie( "$curTimeString: Exception running/posting via CRON: " . $e->getMessage(). "\n" );
				        }   
					}					
			    }	
			}
			$postParams = '';
		    while ($f = current($params)) {
		        if ((key($params) != "access_token") && (key($params) != "scheduled_publish_time") )
		        	$postParams .= key($params).':'.urlencode($f).'|';
		        next($params);
		    }	
		    if (!isset($batchParamsStarted)) {
				$batchParams2 = "{";
				$batchParamsStarted = 1;
			} else {				
				$batchParams2 = ",{";
			}		    	
			$batchParams2 .= '"method":"POST",';
			$batchParams2 .= '"relative_url":"' . $v[ 'feed' ] . '?access_token=' . $params[ "access_token" ] . '","body":"';
		    foreach ($params as $pkey => $pval) {
				if ($pkey != 'access_token' && $pkey != 'isGroupPost' && $pkey != 'postType' && $pkey != 'targetID' ) {					
					$batchParams2 .= "$pkey=" . urlencode($pval) . "&";
				}					
			}
			$batchParams2 .= '"}';
			$batchParams .= ($batchParams2);
			if (!isset($userPosts[$username])) $userPosts[$username] = 0;
		    ++$userPosts[$username];
		    ++$totalPosts;
		    $currentPosts[] = array(
				"user" => $v['user'],
				"params" => $params,
				"postParams" => $postParams,
				"status" => $v[ 'status' ]
			);
			PostDel:
	        $statement = $db->prepare( "DELETE FROM Crons WHERE status = \"" . $v[ 'status' ] . "\"" );
	        if ( $statement ) {
	            $statement->execute();
	        } else {
	            echoDie( "$curTimeString: Del Fail for " . $v[ 'status' ] . " ($username)\n" );
	        }
		}		
    }
    $batchParams .= "]";
    try { 	
    		$graph_url = "https://graph.facebook.com/" . $GLOBALS[ '__FBAPI__' ] ."/";
    		$postData = "batch=" . urlencode( trim( json_encode( $batchParams ), '"')). "&include_headers=false&access_token=".$adminOptions[ "admintoken" ];
    		$postData = str_replace("%5C%22",'%22',$postData);
    		
	    	$ch = curl_init();
	        curl_setopt($ch, CURLOPT_URL, $graph_url);
	        curl_setopt($ch, CURLOPT_HEADER, 0);
	        curl_setopt($ch, CURLOPT_POST, 1);
	        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
	        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

	        $output = curl_exec($ch);
	        curl_close($ch);
            $rr = json_decode($output, true);  
            $i = -1;
            foreach($rr as $ret) {
            	$postlink = 'https://www.facebook.com/';
            	++$i;
            	if ($ret{'code'} != "200") {
            		if (($ret{'code'} == "190") && (isset($ret{'message'})))
            			$error_message = "Application Administrator Token is invalid or expired. Contact Admin to rectify the token via Admin Panel";
            		else
						$error_message = getStringBetween($ret{'body'},'"message":"','","');
            		$statement = $db3->prepare("INSERT INTO Logs VALUES ('".time()."','".$currentPosts[$i]["user"]."','". $currentPosts[$i]["params"]['postType']."','".$currentPosts[$i]["params"][ 'targetID' ]."','".$currentPosts[$i]["params"]['isGroupPost']."','" . $error_message . "','0','".$error_message."','".$currentPosts[$i]["postParams"]."')");
		            if ($statement) {
		                $statement->execute();
		            } else
		            	echoDie( "$curTimeString: Post Failure Logging Fail for " . $currentPosts[$i]{"status"} . ": (".$currentPosts[$i]["user"].")\n");
		            echoDie( "$curTimeString: Post Fail for " . $v[ 'status' ] . ": (" . $currentPosts[$i]{"user"} . ")\n" );
				} else {
					$id_message = getStringBetween($ret{'body'},'"id":"','"');
					if ( strpos( $id_message, "_" ) !== false ) {
			            $postlink .= substr( strstr( $id_message, "_" ), 1 );
			        } else {
			            $postlink .= $id_message;
			        }
			        $statement = $db3->prepare("INSERT INTO Logs VALUES ('".time()."','".$currentPosts[$i]{"user"}."','". $currentPosts[$i]{"params"}['postType']."','".$currentPosts[$i]{"params"}[ 'targetID' ]."','".$currentPosts[$i]{"params"}[ 'isGroupPost' ]."','posted','1','$postlink','".$currentPosts[$i]{"postParams"}."')");
		            if ($statement) {
		                $statement->execute();
		            } else
		            	echoDie( "$curTimeString: Post Logging Fail for " . $currentPosts[$i]["status"] . ": (" . $currentPosts[$i]["user"] . ")\n" );
		            echoDie( "$curTimeString: Posted " . $currentPosts[$i]["status"] . " (" . $currentPosts[$i]["user"] . ")\n" );
				}	        
			}
        }
        catch ( Exception $e ) {
        	echoDie( "$curTimeString: Exception running/posting via CRON: " . $e->getMessage(). "\n" );
        }        
    $db->commit();
    if ($failedPosts && ($totalPosts2 < 50)) {
		$postsToGet = $failedPosts;
		goto selectPosts;
	}
    $db = null;    
}

function echoDie($string = '', $shouldDie = false) {
	if ($string){
		if ( !file_exists( 'cronlog.php' ) || ( filesize( 'cronlog.php' ) > 1048576 ) ) {
			$fp = fopen("cronlog.php", "w");
			fwrite($fp, "<?php\n\r/*\n\r");
			fclose($fp);
		}
		$fp = fopen("cronlog.php", "a");
		fwrite($fp, "$string");
    	fclose($fp);
	}
    if ($shouldDie)
   		die();  
}
?>