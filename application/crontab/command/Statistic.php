<?php


namespace app\crontab\command;

use app\fmyadmin\model\Order;
use app\fmyadmin\server\OrderStatisticServer;
use app\fmyadmin\server\Statistic\StatisticServer;
use app\fmyadmin\server\TemplateMessageServer;
use Carbon\Carbon;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;

class Statistic extends Command
{
    /**
     * 配置(描述)
     */
    protected function configure()
    {
        $this->setName('Statistic')->setDescription("统计");
    }

    /**
     * 执行(默认最近三天，截止昨日)
     *
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     */
    protected function execute(Input $input, Output $output)
    {
        # 记录日志
        Log::notice('统计开始');
        # 执行
        $startDate = Carbon::yesterday()->startOfDay()->subDays(2)->toDateString();
        $endDate = Carbon::yesterday()->toDateString();
        $statistic = new StatisticServer();
        $statistic->exec($startDate, $endDate);
        # 记录日志
        Log::notice('统计结束');
    }
}