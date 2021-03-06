<?php

/**
 * Flash embeds support
 */

SlConfigure::write('Asset.js.footer.swfobject', 'swfobject/swfobject');
SlConfigure::write('Asset.js.jquery', 'head');

?>
<div id="{$id}">
    {t}This page uses flash. To load it, please enable JavaScript!{/t}
</div>
<script type="text/javascript">
	$(function() {
        $("#{$id}").html('<a href="http://www.adobe.com/go/getflashplayer"><img src="http://www.adobe.com/images/shared/download_buttons/get_flash_player.gif" alt="Get Adobe Flash player" /></a>');
        swfobject.embedSWF("{webroot}{$src}{/webroot}", "{$id}", "{$width}", "{$height}", "{$version}", "{$path.vendors}/swfobject/expressInstall.swf", {$flashvars}, {$params}, {$attributes});
    });
    $("#{$id}").html('{t}Please wait. Loading flash content...{/t}');
</script>
<?php

class FlashParser extends PhemeParser {

    function parse($html = null, $blockName = 'document', $blockParams = null) {
        $blockParams = (array)$blockParams;
        $blockParams += array(
            'width' => 300,
            'height' => 120,
            'version' => '9.0.0.0',
            'src' => 'swfobject/test.swf',
            'id' => SL::uniqid(),
            'flashvars' => array(),
            'params' => array(),
            'attributes' => array(),
        );
        if ($html && !preg_match('/[\n]/', $html)) {
            $blockParams['src'] = $html;
            $html = null;
        }

        foreach (array('flashvars', 'params', 'attributes') as $key) {
            $blockParams[$key] = json_encode($blockParams[$key]);
        }

        $this->vars = $blockParams;
        return parent::parse($html, $blockName);
    }
}

Pheme::registerOutputBuffer('Swfobject', new FlashParser(), true);
