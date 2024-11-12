// views/_log_modal.php
<?php
use yii\bootstrap\Modal;
?>

<div id="logViewerModal" class="modal fade" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h4 class="modal-title text-light">Log Viewer</h4>
                <button type="button" class="close text-light" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <pre id="logContent" style="height: 500px; overflow-y: auto; background: #323232; color: #fff; padding: 10px; font-family: 'Courier New', monospace;"></pre>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-outline-light" data-dismiss="modal">Chiudi</button>
            </div>
        </div>
    </div>
</div>

