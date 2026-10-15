<?php

namespace imarc\padstonemason\controllers;

use craft\web\Controller;
use imarc\padstonemason\Plugin;
use yii\web\Response;

class UsageController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requireAdmin();

        $from = $this->request->getParam('from');
        $to = $this->request->getParam('to');

        return $this->renderTemplate('padstone-mason/usage', [
            'rows' => Plugin::getInstance()->usage->totals(
                is_string($from) ? $from : null,
                is_string($to) ? $to : null,
            ),
            'from' => is_string($from) ? $from : '',
            'to' => is_string($to) ? $to : '',
        ]);
    }
}
