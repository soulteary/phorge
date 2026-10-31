<?php

final class PhabricatorProjectWorkspaceChineseTranslation extends PhutilTranslation {

  public function getLocaleCode() {
    return 'zh_CN';
  }

  protected function getTranslations() {
    return array(
      'Advanced Task Search' => '任务高级查询',
      'All Project Activity' => '项目全部活动',
      'Allow current project editors to open unsaved native task drafts from project records while history is active. Disabling rejects direct projectNode requests as well as hiding the action. Ordinary task creation and already saved tasks remain unchanged.' => '历史处于启用状态时，允许当前项目编辑者从记录打开尚未保存的原生任务草稿。停用后同时隐藏入口并拒绝直达 projectNode 请求；普通任务创建和已保存任务不受影响。',
      'Choose a valid source project record.' => '请选择有效的来源节点。',
      'Create Task' => '新建卡片',
      'Create Task from This Record' => '从此记录新建卡片',
      'Create Task in This Column' => '在此列新建卡片',
      'Create Task in This Column: %s' => '在此列新建卡片：%s',
      'Development History' => '开发历史',
      'Filter: %s' => '筛选：%s',
      'Latest Project Record' => '最新项目记录',
      'Latest Work Comment' => '最新工作评论',
      'Milestones group phase tasks. Record releases, decisions, and results in %s.' => '里程碑用于组织阶段任务。发布、决策和成果可记录在%s中。',
      'Native Workboard' => '原生工作看板',
      'Native preserves the existing workboard. Unified adds the project workspace, native task-status badges, and shared controls. This setting does not change tasks, columns, policies, or status defaults.' => '原生模式保留既有看板；统一模式增加项目工作区、原生任务状态徽标和公共控件。此设置不修改任务、列、策略或默认状态。',
      'Not Reported' => '尚未填写',
      'Original Node Status: %s (Historical Information)' => '原节点状态：%s（来源历史信息）',
      'Reported Completion' => '填报完成度',
      'Project Change History' => '项目变更历史',
      'Project Workspace' => '工作看板',
      'Publish Project Record' => '发布项目记录',
      'Sort: %s' => '排序：%s',
      'Source: [[%s | Project Record #%s]]
Original Author: %s
Originally Published: %s (UTC)' => '来源：[[%s | 项目记录 #%s]]
原记录作者：%s
原记录发布时间：%s（UTC）',
      'Task Workboard' => '任务看板',
      'Tasks own their status, responsibility, and discussion. A project tag does not share a private task.' => '任务独立维护状态、负责人和讨论。项目标签不会自动共享私有任务。',
      'Tasks own their status, responsibility, and discussion. Project history preserves results and discussion. A project tag does not share a private task; task comments retain task policies. Time and author refer to the actual task creation time and creator.' => '卡片用于推进工作，状态、负责人和讨论在卡片内维护。开发历史保存项目级成果与讨论。项目标签不会自动共享卡片；任务内评论按任务自身权限控制；时间和发布成员指卡片的实际创建时间与创建者。',
      'The source record is invalid, is not visible, or you do not have current project edit permission.' => '来源记录无效、不可查看，或您没有该项目的编辑权限。',
      'This record cannot be used as a task source.' => '此记录不能作为任务来源。',
      'Unable to Create Task from Project Record' => '无法从项目记录创建卡片',
      'Unable to create a task from the project record.' => '无法从项目记录创建卡片。',
      'Unified Project Workspace' => '统一项目工作区',
      'You do not have permission to create a task from this record.' => '您没有从此记录创建任务的权限。',
      'You do not have permission to create tasks.' => '您没有创建任务的权限。',
    );
  }
}
