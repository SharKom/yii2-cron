<?php
// assets/LogViewerAsset.php
namespace sharkom\cron\assets;

use yii\web\AssetBundle;

class LogViewerAsset extends AssetBundle
{
    public $sourcePath = '@vendor/sharkom/yii2-cron/assets';


    public $js = [
        'js/log-viewer.js',
        'js/lazy_loader-log-commands.js',
    ];

    public $depends = [
        'yii\web\JqueryAsset',
        'yii\bootstrap\BootstrapAsset',
        'yii\bootstrap\BootstrapPluginAsset',
    ];

    /**
     * Initialize the assets forcing the copy to public directory
     */
    public function init()
    {
        parent::init();
        $this->publishOptions['forceCopy'] = true;
    }
}