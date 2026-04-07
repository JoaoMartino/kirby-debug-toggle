panel.plugin('Martino/debug-toggle', {
  components: {
    'k-debug-toggle-view': {
      data() {
        return {
          loading: false,
          toggling: false,
          debug: false,
          enabledBy: null,
          enabledAt: null,
          expiresAt: null,
          expired: false
        };
      },
      
      created() {
        this.loadState();
      },
      
      methods: {
        async loadState() {
          this.loading = true;
          
          try {
            const response = await this.$api.get('debug-toggle/state');
            this.debug = response.debug;
            this.enabledBy = response.enabled_by;
            this.enabledAt = response.enabled_at;
            this.expiresAt = response.expires_at;
            this.expired = response.expired;
          } catch (error) {
            this.$panel.notification.error(error.message || 'Failed to load debug state');
          } finally {
            this.loading = false;
          }
        },
        
        async toggle() {
          this.toggling = true;
          
          try {
            const response = await this.$api.post('debug-toggle/state', {
              enabled: !this.debug
            });
            
            this.debug = response.debug;
            this.enabledBy = response.enabled_by;
            this.enabledAt = response.enabled_at;
            this.expiresAt = response.expires_at;
            this.expired = response.expired;
            
            const message = this.debug 
              ? 'Debug mode enabled' 
              : 'Debug mode disabled';
            this.$panel.notification.success(message);
          } catch (error) {
            this.$panel.notification.error(error.message || 'Failed to toggle debug state');
          } finally {
            this.toggling = false;
          }
        }
      },
      
      template: `
        <k-panel-inside>
          <k-header>Debug Mode</k-header>
          
          <div v-if="loading" class="k-text" style="padding: var(--spacing-6);">
            Loading...
          </div>
          
          <div v-else class="debug-toggle-card" :class="{ 'is-active': debug, 'is-inactive': !debug }">
            <div class="debug-card-content">
              <div class="debug-info-row">
                <span class="debug-label">Status</span>
                <span class="debug-value">{{ debug ? 'ON' : 'OFF' }}</span>
              </div>
              <div class="debug-info-row" :class="{ 'is-dimmed': !debug }">
                <span class="debug-label">Enabled by</span>
                <span class="debug-value">{{ enabledBy || '' }}</span>
              </div>
              <div class="debug-info-row" :class="{ 'is-dimmed': !debug }">
                <span class="debug-label">Expires at</span>
                <span class="debug-value">{{ expiresAt || '' }}</span>
              </div>
              
              <div class="debug-warning">
                <span v-if="debug" class="debug-warning-active">
                  Warning: <span class="debug-warning-active-text">Debug is active.</span><br>
                  Errors and file paths are visible to all visitors.<br>
                  Auto-disables at {{ expiresAt }}.
                </span>
                <span v-else class="debug-warning-inactive">
                  <span>Debug is inactive.</span><br>
                  Errors and file paths are hidden to all visitors.
                </span>
              </div>
              
              <div class="debug-indicator" :class="{ 'is-active': debug }"></div>
            </div>
            
            </div>
            <k-button
              class="debug-toggle-button"
              :class="{ 'is-active': debug }"
              variant="filled"
              size="lg"
              :disabled="toggling"
              @click="toggle"
            >
              {{ toggling ? 'Please wait...' : (debug ? 'Turn OFF' : 'Turn ON') }}
            </k-button>
        </k-panel-inside>
      `
    }
  }
});
