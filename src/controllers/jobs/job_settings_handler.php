<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/ScheduleService.php';
require_once __DIR__ . '/../../utils/acl.php';

$jobId=(int)($_POST['job_id']??0);$locationId=(int)($_POST['default_service_location_id']??0);$status=(string)($_POST['status']??'not_started');
if($jobId<=0||!in_array($status,['not_started','active','completed','cancelled'],true)){http_response_code(422);exit('Invalid Job settings.');}
require_record_ownership($pdo,'jobs',$jobId);
$jobStatement=$pdo->prepare('SELECT id,client_id,project_id FROM jobs WHERE id=? AND archived=0');$jobStatement->execute([$jobId]);$job=$jobStatement->fetch(PDO::FETCH_ASSOC);if(!$job){http_response_code(404);exit('Job not found.');}
if($locationId>0){
  $location=$pdo->prepare('SELECT s.id FROM service_locations s WHERE s.id=? AND s.archived=0 AND (s.client_id=? OR s.project_id=? OR EXISTS(SELECT 1 FROM project_service_locations psl WHERE psl.project_id=? AND psl.service_location_id=s.id))');$location->execute([$locationId,(int)$job['client_id'],$job['project_id']?:null,$job['project_id']?:null]);if(!$location->fetchColumn()){http_response_code(422);exit('Choose a service location assigned to this Job client or Project.');}
  if(!empty($job['project_id'])){$allowed=$pdo->prepare('SELECT COUNT(*) FROM project_service_locations WHERE project_id=?');$allowed->execute([(int)$job['project_id']]);if((int)$allowed->fetchColumn()>0){$member=$pdo->prepare('SELECT id FROM project_service_locations WHERE project_id=? AND service_location_id=?');$member->execute([(int)$job['project_id'],$locationId]);if(!$member->fetchColumn()){http_response_code(409);exit('Choose a service location allowed by the Job Project.');}}}
}
$pdo->prepare('UPDATE jobs SET default_service_location_id=?,status=?,notes=? WHERE id=?')->execute([$locationId?:null,$status,trim((string)($_POST['notes']??''))?:null,$jobId]);
ScheduleService::syncJob($pdo,$jobId,(string)($appConfig['timezone']??'UTC'),(int)($_SESSION['user']['id']??0));
header('Location: /?page=jobs/jobs-list&selected_project_code='.rawurlencode((string)($_POST['job_code']??'')).'&job_saved=1');exit;
