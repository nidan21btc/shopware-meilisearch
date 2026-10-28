/* No external packages or runtime downloads. Loaded as a Shopware administration extension. */
Shopware.Component.register('sw-meilisearch-reindex', {
    inject: ['loginService', 'acl'],
    mixins: [Shopware.Mixin.getByName('notification')],
    template: `<div><p>Der Suchindex wird automatisch einmal täglich aktualisiert. Ein manueller Neuaufbau läuft im Hintergrund und umfasst alle aktivierten Verkaufskanäle und Sprachen. Bitte zuvor Änderungen speichern.</p><sw-button :disabled="busy || pending || !acl.can('system_config:update')" :is-loading="busy" @click="rebuild">Suchindex jetzt neu aufbauen</sw-button><p v-if="pending" role="status">Neuaufbau vorgemerkt. Der Hintergrunddienst startet ihn in Kürze; bei einem Fehler wird frühestens nach 15 Minuten erneut versucht.</p><p v-if="indexedAt">Letzter erfolgreicher Neuaufbau: {{ indexedAt }} UTC</p><sw-button :disabled="busy" @click="refresh">Status aktualisieren</sw-button></div>`,
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
