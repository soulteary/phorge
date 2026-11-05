<?php
// Synthetic primitive rows used by native pure tests; never SQL or authority proof.
function governance_project_source_control_rows($retained=false){
  $user='PHID-USER-'.str_repeat('u',20);$root='PHID-PROJ-'.str_repeat('r',20);
  $leaf='PHID-PROJ-'.str_repeat('l',20);$mile='PHID-PROJ-'.str_repeat('m',20);
  $project=function($id,$phid,$key,$parent=null,$milestone=null,$status='0'){
    return array('id'=>(string)$id,'phid'=>$phid,'status'=>$status,'parentProjectPHID'=>$parent,
      'milestoneNumber'=>$milestone,'projectPath'=>($parent?'rrrr':'').$key,'projectPathKey'=>$key,
      'projectDepth'=>$parent?'1':'0','hasSubprojects'=>$parent?'0':'1','hasMilestones'=>$parent?'0':'1',
      'viewPolicy'=>'users','editPolicy'=>'admin','joinPolicy'=>'users','spacePHID'=>null,
      'subtype'=>'default','isMembershipLocked'=>'0');
  };
  $edge=static function($src,$type,$dst){return array('src'=>$src,'type'=>(string)$type,'dst'=>$dst,
    'dateCreated'=>'1','seq'=>'0','dataID'=>null);};
  $direct=$retained?$root:$leaf;$material=$retained?array($mile):array($root,$leaf);
  $project_edges=array($edge($direct,13,$user));
  foreach($material as$phid){$project_edges[]=$edge($phid,60,$user);}
  return array('subject'=>$user,'root'=>$root,'leaf'=>$leaf,'milestone'=>$mile,
    'snapshot'=>array('subject_row'=>array('id'=>'9','phid'=>$user),
      'projects'=>array($root=>$project(1,$root,'rrrr'),$leaf=>$project(2,$leaf,'llll',$root,null,'100'),$mile=>$project(3,$mile,'mmmm',$root,'1')),
      'selected'=>array($root=>true,$leaf=>true,$mile=>true),'children'=>array($root=>array($mile,$leaf),$leaf=>array(),$mile=>array()),
      'user_edges'=>array($edge($user,14,$direct)),'project_edges'=>$project_edges,'history'=>array()));
}
