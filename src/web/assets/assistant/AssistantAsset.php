<?php

namespace imarc\padstonemason\web\assets\assistant;

use craft\web\AssetBundle;

class AssistantAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [];
        $this->js = ['assistant.js'];
        $this->css = ['assistant.css'];

        parent::init();
    }
}
