<?php
/* *****************************************************************
 * @Author: wushuiyong
 * @Created Time : 五  7/31 22:21:23 2015
 *
 * @File Name: command/Folder.php
 * @Description:
 * *****************************************************************/
namespace app\components;

use Yii;
use app\models\Project;
use app\models\Task as TaskModel;


/**
 * Class FolderRsync
 * @package app\components
 */
class FolderRsync extends Folder
{


    public function setExeStatus($status)
    {
        $this->status = $status;
    }


    public function setExeLog($log)
    {
        $this->log = $log;
    }


    /**
     * 初始化宿主机部署工作空间
     *
     * @param TaskModel $task
     * @return bool|int
     */
    public function initLocalWorkspace(TaskModel $task)
    {

        $version = $task->link_id;
        $branch = $task->branch;

//        if ($this->config->repo_type == Project::REPO_SVN) {
//            // svn cp 过来指定分支的目录, 然后 svn up 到指定版本
//            $cmd[] = sprintf('cp -rfa %s %s ', Project::getSvnDeployBranchFromDir($branch), Project::getDeployWorkspace($version));
//        } else {
//            // git cp 仓库, 然后 checkout 切换分支, up 到指定版本
//            $cmd[] = sprintf('cp -rfa %s %s ', Project::getDeployFromDir(), Project::getDeployWorkspace($version));
//        }

        $cmd[] = sprintf('ln -sb %s %s ', Project::getDeployFromDir(), Project::getDeployWorkspace($version));

        $command = join(' && ', $cmd);
        return $this->runLocalCommand($command);
    }


    /**
     * 将多个文件/目录通过tar + scp传输到指定的多个目标机
     *
     * @param Project $project
     * @param TaskModel $task
     * @return bool
     * @throws \Exception
     */
    public function scpCopyFiles(Project $project, TaskModel $task)
    {

        if ($this->_findPackagePath($project, $task)) {
            return $this->_rsync($project, $task);

        } else {
            return $this->rsyncCopyFiles($project, $task);
        }
    }


    /**
     * 全量 rsyncCopyFiles
     * @param Project $project
     * @param TaskModel $task
     */
    private function rsyncCopyFiles(Project $project, TaskModel $task)
    {

        $version = $task->link_id;
        $releaseDeployPath = Project::getDeployWorkspace($version);
        $releasePath = Project::getReleaseVersionDir($version);

        $this->log("rsyncCopyFiles start {$releaseDeployPath} => {$releasePath}");
        $excludes = GlobalHelper::str2arr($project->excludes);

        //当前线上版本
        //查找目标目录是否存在
        foreach (Project::getHosts() as $remoteHost) {

            $command = sprintf('rsync -av --delete %s %s/* %s@%s:%s',
                $this->excludes($excludes),
                $releaseDeployPath,
                escapeshellarg($this->getConfig()->release_user),
                escapeshellarg($this->getHostName($remoteHost)),
                $releasePath
            );
            $ret = $this->runLocalCommand($command);
            if (!$ret) {
                //走老发布逻辑
                return false;
            }
        }

        return true;

    }


    /**
     * 目标机器，查找上一次部署的目录
     * 找到就直接 cp, 然后传输
     *
     * @return bool
     */
    private function _findPackagePath(Project $project, TaskModel $task)
    {

        //当前线上版本
        //查找目标目录是否存在
        foreach (Project::getHosts() as $remoteHost) {
            // 循环 scp 传输
            $status = $this->_checkHostCurrentPath($remoteHost, $project, $task);
            if (!$status) {
                return false;
            }
        }
        return true;
    }


    /**
     * @param Project $project
     * @param TaskModel $task
     * @return bool
     * @throws \Exception
     */
    protected function _rsync(Project $project, TaskModel $task)
    {

        $version = $task->link_id;
        $releaseDeployPath = Project::getDeployWorkspace($version);
        $releasePath = Project::getReleaseVersionDir($version);

        $cmd = [];

        $exVersion = $project->version;
        $extReleasePath = Project::getReleaseVersionDir($exVersion);
        $excludes = GlobalHelper::str2arr($project->excludes);
        $cmd[] = sprintf('rsync -a %s %s/* %s',
            $this->excludes($excludes),
            $extReleasePath,
            $releasePath
        );

        $command = join(' && ', $cmd);
        $ret = $this->runRemoteCommand($command);

        if (!$ret) {
            throw new \Exception(yii::t('walle', 'unpackage error'));
        }

        $this->log("_rsync: 复制远程目标目录成功 {$extReleasePath} => {$releasePath}");

        foreach (Project::getHosts() as $remoteHost) {
            // 循环 scp 传输
            $command = sprintf('rsync -rtopgDlvz --delete %s %s/* %s@%s:%s',
                $this->excludes($excludes),
                $releaseDeployPath,
                escapeshellarg($this->getConfig()->release_user),
                escapeshellarg($this->getHostName($remoteHost)),
                $releasePath
            );

            $ret = $this->runLocalCommand($command);
            if (!$ret) {
                //走老发布逻辑
                return false;
            }

            $this->log("_rsync: 同步目标目录成功 local: {$releaseDeployPath} => {$this->getHostName($remoteHost)}: {$releasePath}");
        }


        return true;
    }

    /**
     * 检查目标机器，指定版本目录是否存在
     * @param $remoteHost
     * @param Project $project
     * @param TaskModel $task
     * @return bool
     * @throws \Exception
     */
    private function _checkHostCurrentPath($remoteHost, Project $project, TaskModel $task)
    {

        $exVersion = $project->version;
        if (empty($exVersion)) {
            $this->log('---- _checkHostPath: 当前项目未发布过，所以走老发布流程 ' . $project->name . ': ' . $task->link_id);
            return false;
        }

        $extReleasePath = Project::getReleaseVersionDir($exVersion);

        $version = $task->link_id;
        $releasePath = Project::getReleaseVersionDir($version);


        $scpCommand = sprintf('ssh -p %d %s@%s "[ -d %s ] && rm -f %s/* && echo $?;"',
            $this->getHostPort($remoteHost),
            escapeshellarg($this->getConfig()->release_user),
            escapeshellarg($this->getHostName($remoteHost)),
            $extReleasePath,
            $releasePath
        );

        $this->log('---- 判断目标目录是否存在' . $scpCommand);

        $ret = $this->runLocalCommand($scpCommand);
        if (!$ret) {
            //走老发布逻辑
            return false;
        }

        return true;

    }

}

