<?php
require_once dirname(__DIR__) . '/index.class.php';

class PdfuploaderIndexManagerController extends PdfuploaderMainController
{
    public function getPageTitle() { return 'Документы PDF'; }

    private function slug($s) {
        $map = ['ё'=>'e','й'=>'i','ю'=>'yu','я'=>'ya','ч'=>'ch','ш'=>'sh','щ'=>'sch','ж'=>'zh','х'=>'h','ц'=>'c','ъ'=>'','ь'=>'','ы'=>'y','э'=>'e'];
        $s = mb_strtolower($s,'UTF-8');
        $s = strtr($s, $map);
        $s = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
        $s = preg_replace('~[^a-z0-9]+~','-',$s);
        return trim($s,'-') ?: 'default';
    }

    public function loadCustomCssJs() {
        $assetsUrl    = $this->modx->getOption('assets_url') . 'components/pdfuploader/';
        $connectorUrl = $assetsUrl . 'connector.php';
        $rid   = (int)($_GET['rid'] ?? 0);
        $tvKey = $this->modx->getOption('pdfuploader.folder_tv', null, 'uploadFolder');
        $def   = $this->modx->getOption('pdfuploader.default_folder', null, 'default');
        $defaultFolder = $def;

        if ($rid > 0 && $tvKey) {
            if ($tv = $this->modx->getObject('modTemplateVar', ['name'=>$tvKey])) {
                $val = (string)$tv->getValue($rid);
                if ($val !== '') $defaultFolder = $val;
            }
        }

        if ($rid > 0 && $defaultFolder === $def) {
            $this->modx->addPackage('minishop2', MODX_CORE_PATH.'components/minishop2/model/', $this->modx->config['table_prefix']);
            if ($pd = $this->modx->getObject('msProductData', ['id'=>$rid])) {
                $vid = (int)$pd->get('vendor');
                if ($vid && ($v = $this->modx->getObject('msVendor', $vid))) {
                    $defaultFolder = $this->slug($v->get('name'));
                }
            }
        }

        $cfg = [
            'assetsUrl'     => $assetsUrl,
            'connectorUrl'  => $connectorUrl,
            'defaultFolder' => $defaultFolder,
            'rid'           => $rid,
            'folderTv'      => $tvKey,
        ];
        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        $this->setPlaceholder('assets_url', $assetsUrl);
        $this->setPlaceholder('connector_url', $connectorUrl);
        $this->setPlaceholder('default_folder', $defaultFolder);
        $this->setPlaceholder('rid', $rid);
        $this->addHtml("<script>window.PDFUP_CONF={$json};</script>");
    }

    public function getTemplateFile() {
        return dirname(__DIR__) . '/templates/home.tpl';
    }
}
