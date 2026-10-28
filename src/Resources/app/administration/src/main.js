/* No external packages or runtime downloads. Loaded as a Shopware administration extension. */
Shopware.Component.register('sw-meilisearch-reindex', {
    inject: ['loginService', 'acl'],
    mixins: [Shopware.Mixin.getByName('notification')],
    template: `
        <section class="sw-meilisearch-reindex" aria-labelledby="sw-meilisearch-reindex-heading">
            <div class="sw-meilisearch-reindex__intro">
                <h3 id="sw-meilisearch-reindex-heading" class="sw-meilisearch-reindex__title">Suchindex</h3>
                <p class="sw-meilisearch-reindex__description">Der Suchindex wird einmal täglich automatisch aktualisiert. Ein manueller Neuaufbau läuft im Hintergrund für alle aktivierten Verkaufskanäle und Sprachen.</p>
                <p class="sw-meilisearch-reindex__hint">Bitte speichere Änderungen, bevor du den Neuaufbau startest.</p>
            </div>
            <div v-if="indexedAt" class="sw-meilisearch-reindex__last-build">
                <span>Letzter erfolgreicher Neuaufbau</span>
                <strong>{{ formattedIndexedAt }}</strong>
            </div>
            <mt-banner v-if="pending" variant="info" class="sw-meilisearch-reindex__notice" role="status">
                <strong>Neuaufbau vorgemerkt</strong><br>
                Der Hintergrunddienst startet ihn in Kürze. Bei einem Fehler wird frühestens nach 15 Minuten erneut versucht.
            </mt-banner>
            <div class="sw-meilisearch-reindex__actions">
                <sw-button-process variant="primary" :process-success="false" :disabled="busy || pending || !acl.can('system_config:update')" :is-loading="busy" @click="rebuild">Suchindex jetzt neu aufbauen</sw-button-process>
                <sw-button :disabled="busy" @click="refresh">Status aktualisieren</sw-button>
            </div>
        </section>`,
    computed: {
        formattedIndexedAt() {
            const raw = this.indexedAt;
            if (!raw) return '';
            const date = new Date(raw.replace(' ', 'T') + 'Z');
            if (Number.isNaN(date.getTime())) return `${raw} UTC`;
            return new Intl.DateTimeFormat('de-DE', {dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC'}).format(date) + ' UTC';
        },
    },
    data() { return {busy: false, pending: false, indexedAt: null}; },
    created() { this.refresh(); },
    methods: {
        async call(method) {
            const http = Shopware.Application.getContainer('init').httpClient;
            const result = await http[method]('_action/shopware-meilisearch/reindex', ...(method === 'post' ? [{}, {headers: {Authorization: `Bearer ${this.loginService.getToken()}`}}] : [{headers: {Authorization: `Bearer ${this.loginService.getToken()}`}}]));
            this.pending = result.data.pending;
            this.indexedAt = result.data.indexedAt;
        },
        async refresh() {
            this.busy = true;
            try { await this.call('get'); }
            catch (_) { this.createNotificationError({message: 'Der Suchindex-Status konnte nicht geladen werden.'}); }
            finally { this.busy = false; }
        },
        async rebuild() {
            this.busy = true;
            try { await this.call('post'); this.createNotificationSuccess({message: 'Der Suchindex-Neuaufbau wurde vorgemerkt.'}); }
            catch (_) { this.createNotificationError({message: 'Der Neuaufbau konnte nicht vorgemerkt werden.'}); }
            finally { this.busy = false; }
        },
    },
});
