<?php declare(strict_types = 1);

/** @var array $data */
require_once __DIR__.'/../helpers/ReportRenderer.php';

(new CHtmlPage())
    ->setTitle($data['title'])
    ->addItem(new CObject(\Modules\SlaUptimeReport\Helpers\ReportRenderer::render($data)))
    ->show();
