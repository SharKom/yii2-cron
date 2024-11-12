let logViewer;
function showLogViewer(file) {
    $('#logViewerModal').modal('show');
    if (!logViewer) {
        logViewer = new LogViewer('#logViewerModal', 'index.php?r=cron/commands-spool/tail&file='+file);
    }
    logViewer.start();
}


class LogViewer {
    constructor(modalId, logUrl) {
        this.modal = $(modalId);
        this.content = this.modal.find('#logContent');
        this.logUrl = logUrl;
        this.lastTimestamp = 0;
        this.isActive = false;
        this.interval = null;
    }

    start() {
        this.isActive = true;
        this.update();
        this.interval = setInterval(() => this.update(), 1000);
        this.modal.on('hidden.bs.modal', () => {
            this.stop();
        });
    }

    stop() {
        this.isActive = false;
        if (this.interval) {
            clearInterval(this.interval);
        }
    }

    async update() {
        if (!this.isActive) return;
        try {
            const response = await $.get(this.logUrl);
            if (response.timestamp > this.lastTimestamp) {
                this.content.html(response.content);
                this.lastTimestamp = response.timestamp;
                this.content.scrollTop(this.content[0].scrollHeight);
            }
        } catch (error) {
            console.error('Errore nel recupero dei log:', error);
        }
    }
}