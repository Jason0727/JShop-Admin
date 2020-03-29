<?php


namespace app\crontab\command;


use search\WexinSmall;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;

class SyncSearch extends Command
{
    /**
     * 配置(描述)
     */
    protected function configure()
    {
        $this->setName('SyncSearch')->setDescription("同步搜索"); // 目前只支持微信小程序
    }

    /**
     * 执行
     *
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     */
    protected function execute(Input $input, Output $output)
    {
        Log::notice("同步搜索开始");
        $search = new WexinSmall();
        $bool = $search->exec();
        Log::notice("同步搜索结束,结果【" . $bool . "】");
    }
}