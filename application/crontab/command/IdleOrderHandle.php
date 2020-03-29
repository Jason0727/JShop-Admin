<?php


namespace app\crontab\command;


use idle\OrderHandle;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;

/**
 * 闲鱼订单变更事件
 *
 * Class IdleOrderHandle
 * @package app\crontab\command
 */
class IdleOrderHandle extends Command
{
    /**
     * 配置
     */
    protected function configure()
    {
        $this->setName('IdleOrderHandle')->setDescription("闲鱼订单变更事件");
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
        Log::info("执行闲鱼订单变更事件");
        $orderHandle = new OrderHandle();
        $orderHandle->orderHandle();
    }
}