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

// The administration stores the same JSON strings consumed by the PHP indexer.
// Empty values and inherited values are not written until the operator edits them.
const indexFieldLabels = {
    productNumber: 'Artikelnummer', ean: 'EAN', manufacturerNumber: 'Herstellernummer',
    name: 'Produktname', quantity: 'Menge', keywords: 'Suchbegriffe',
    customSearchKeywords: 'Eigene Suchbegriffe', manufacturer: 'Hersteller',
    manufacturerDescription: 'Herstellerbeschreibung', properties: 'Eigenschaften',
    options: 'Variantenoptionen', categories: 'Kategorien', description: 'Beschreibung',
    metaTitle: 'Meta-Titel', metaDescription: 'Meta-Beschreibung', ogTitle: 'Open-Graph-Titel',
    ogDescription: 'Open-Graph-Beschreibung', packUnit: 'Verpackungseinheit',
    packUnitPlural: 'Verpackungseinheit (Mehrzahl)', unit: 'Einheit', unitShortCode: 'Einheitenkürzel',
    deliveryTime: 'Lieferzeit', weight: 'Gewicht', width: 'Breite', height: 'Höhe', length: 'Länge',
    purchaseUnit: 'Verkaufseinheit', referenceUnit: 'Referenzeinheit', releaseDate: 'Erscheinungsdatum',
};
const defaultIndexFields = ['productNumber', 'ean', 'manufacturerNumber', 'name', 'quantity', 'keywords', 'customSearchKeywords', 'manufacturer', 'properties', 'categories', 'description'];
const knownLocales = ['de-DE', 'en-GB', 'es-ES', 'fr-FR', 'it-IT'];
function parseSetting(value, fallback, kind) {
    if (value === null || value === undefined || (typeof value === 'string' && value.trim() === '')) return {data: fallback, valid: true};
    try {
        const parsed = JSON.parse(value);
        const valid = kind === 'list' ? Array.isArray(parsed) && parsed.every(item => typeof item === 'string')
            : parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed);
        return {data: valid ? parsed : fallback, valid};
    } catch (_) { return {data: fallback, valid: false}; }
}
function localeKeys(object) { return [...new Set([...knownLocales, ...Object.keys(object)])]; }
function validIndexFields(fields) {
    return Array.isArray(fields) && fields.length >= 1 && fields.length <= 64 && new Set(fields).size === fields.length && fields.every(field => typeof field === 'string' && (Object.hasOwn(indexFieldLabels, field) || /^customFields\.[a-zA-Z0-9_]{1,128}$/.test(field)));
}

Shopware.Component.register('sw-meilisearch-field-list', {
    inject: ['repositoryFactory'],
    props: {value: {type: Array, required: true}, disabled: {type: Boolean, default: false}},
    emits: ['update:value'],
    data() { return {selectedField: '', customFieldId: null, customError: '', customBusy: false}; },
    computed: {
        availableFields() { return Object.entries(indexFieldLabels).filter(([key]) => !this.value.includes(key)); },
    },
    template: `
      <div class="sw-meilisearch-config__field-list">
        <p class="sw-meilisearch-config__help">Die Reihenfolge bestimmt die Gewichtung: weiter oben bedeutet höhere Priorität.</p>
        <ol class="sw-meilisearch-config__ordered-list">
          <li v-for="(field, index) in value" :key="field" class="sw-meilisearch-config__item">
            <span class="sw-meilisearch-config__item-position" aria-hidden="true">{{ index + 1 }}.</span>
            <span class="sw-meilisearch-config__item-name">
              <span>{{ fieldLabel(field) }}</span>
              <small class="sw-meilisearch-config__item-technical">{{ field }}</small>
            </span>
            <div class="sw-meilisearch-config__item-actions">
              <sw-button size="small" :disabled="disabled || index === 0" :aria-label="fieldLabel(field) + ' nach oben'" @click="move(index, -1)">↑</sw-button>
              <sw-button size="small" :disabled="disabled || index === value.length - 1" :aria-label="fieldLabel(field) + ' nach unten'" @click="move(index, 1)">↓</sw-button>
              <sw-button size="small" :disabled="disabled || value.length <= 1" :aria-label="fieldLabel(field) + ' entfernen'" @click="remove(index)">Entfernen</sw-button>
            </div>
          </li>
        </ol>
        <div class="sw-meilisearch-config__add-row">
          <label>Standardfeld hinzufügen
            <select v-model="selectedField" :disabled="disabled || !availableFields.length" class="sw-meilisearch-config__input">
              <option value="">Feld auswählen</option><option v-for="entry in availableFields" :key="entry[0]" :value="entry[0]">{{ entry[1] }} ({{ entry[0] }})</option>
            </select>
          </label>
          <sw-button :disabled="disabled || !selectedField || value.length >= 64" @click="addStandard">Hinzufügen</sw-button>
        </div>
        <div class="sw-meilisearch-config__add-row">
          <sw-entity-single-select entity="custom_field" label="Produkt-Zusatzfeld hinzufügen" :value="customFieldId" :disabled="disabled || customBusy || value.length >= 64" @update:value="customFieldId = $event; customError = ''" />
          <sw-button :disabled="disabled || customBusy || !customFieldId || value.length >= 64" aria-label="Produkt-Zusatzfeld hinzufügen" @click="addCustom">Hinzufügen</sw-button>
        </div>
        <p v-if="customError" role="alert" class="sw-meilisearch-config__error">{{ customError }}</p>
        <p class="sw-meilisearch-config__help">Zusatzfelder müssen Produkten zugeordnet sein; es werden sprachabhängige skalare Werte indiziert.</p>
      </div>`,
    methods: {
        fieldLabel(field) { return indexFieldLabels[field] || (field.startsWith('customFields.') ? 'Zusatzfeld: ' + field.slice(13) : field); },
        move(index, delta) { const next = [...this.value]; const target = index + delta; if (target < 0 || target >= next.length) return; [next[index], next[target]] = [next[target], next[index]]; this.$emit('update:value', next); },
        remove(index) { if (this.value.length <= 1) return; this.$emit('update:value', this.value.filter((_, i) => i !== index)); },
        addStandard() { if (this.selectedField && !this.value.includes(this.selectedField) && this.value.length < 64) this.$emit('update:value', [...this.value, this.selectedField]); this.selectedField = ''; },
        async addCustom() {
            if (this.disabled || this.customBusy || !this.customFieldId || this.value.length >= 64) return;
            const id = this.customFieldId;
            this.customError = ''; this.customBusy = true;
            try {
                const field = await this.repositoryFactory.create('custom_field').get(id, Shopware.Context.api);
                if (this.disabled || this.value.length >= 64) return;
                const name = field && field.name;
                if (!/^[a-zA-Z0-9_]{1,128}$/.test(name || '')) throw new Error('invalid');
                const key = 'customFields.' + name;
                if (this.value.includes(key)) { this.customError = 'Dieses Zusatzfeld ist bereits ausgewählt.'; return; }
                this.$emit('update:value', [...this.value, key]);
                this.customFieldId = null;
            } catch (_) { this.customError = 'Das Zusatzfeld konnte nicht geladen werden. Bitte Berechtigungen prüfen.'; }
            finally { this.customBusy = false; }
        },
    },
});

Shopware.Component.register('sw-meilisearch-index-fields', {
    props: {value: {type: String, default: ''}, disabled: {type: Boolean, default: false}},
    emits: ['update:value'],
    computed: { parsed() { const x = parseSetting(this.value, [...defaultIndexFields], 'list'); if (x.valid && !validIndexFields(x.data)) x.valid = false; return x; } },
    template: `
      <div class="sw-meilisearch-config">
        <sw-meilisearch-field-list v-if="parsed.valid" :value="parsed.data" :disabled="disabled" @update:value="save" />
        <template v-else><p role="alert" class="sw-meilisearch-config__error">Die bestehende Einstellung ist kein gültiges JSON. Bitte korrigieren; sie wird nicht automatisch überschrieben.</p><textarea class="sw-meilisearch-config__input" :value="value" :disabled="disabled" @input="$emit('update:value', $event.target.value)"></textarea></template>
        <sw-button :disabled="disabled || !value" @click="$emit('update:value', '')">Standardfelder verwenden</sw-button>
        <p class="sw-meilisearch-config__help">Änderungen speichern und den Suchindex neu aufbauen.</p>
      </div>`,
    methods: {save(fields) { this.$emit('update:value', JSON.stringify(fields)); }},
});

Shopware.Component.register('sw-meilisearch-language-fields', {
    props: {value: {type: String, default: ''}, disabled: {type: Boolean, default: false}},
    emits: ['update:value'],
    computed: {
        parsed() {
            const x = parseSetting(this.value, {}, 'object');
            if (x.valid && Object.entries(x.data).some(([locale, fields]) => !/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})+$/.test(locale) || !validIndexFields(fields))) x.valid = false;
            return x;
        },
        locales() { return [...new Set([...localeKeys(this.parsed.data), this.locale])]; },
        fields() { return this.parsed.data[this.locale] || null; },
        localeValid() { return /^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})+$/.test(this.newLocale); },
    },
    template: `
      <div class="sw-meilisearch-config">
        <template v-if="parsed.valid">
          <p class="sw-meilisearch-config__help">Ohne Ausnahme verwendet eine Sprache die allgemeine Feldauswahl.</p>
          <label>Sprache auswählen <select v-model="locale" class="sw-meilisearch-config__input"><option v-for="item in locales" :key="item" :value="item">{{ item }}</option></select></label>
          <template v-if="fields"><sw-meilisearch-field-list :value="fields" :disabled="disabled" @update:value="saveFields" /><sw-button :disabled="disabled" @click="removeOverride">Ausnahme entfernen</sw-button></template>
          <template v-else><p class="sw-meilisearch-config__help">Für {{ locale }} ist keine Ausnahme hinterlegt.</p><sw-button :disabled="disabled" @click="saveFields([...defaults])">Ausnahme mit Standardfeldern anlegen</sw-button></template>
          <div class="sw-meilisearch-config__add-row"><label>Weitere Sprachkennung <input v-model.trim="newLocale" :disabled="disabled" class="sw-meilisearch-config__input" placeholder="z. B. nl-NL"></label><sw-button :disabled="disabled || !localeValid" @click="addLocale">Sprache auswählen</sw-button></div>
        </template>
        <template v-else><p role="alert" class="sw-meilisearch-config__error">Die bestehende Einstellung ist kein gültiges JSON. Bitte korrigieren; sie wird nicht automatisch überschrieben.</p><textarea class="sw-meilisearch-config__input" :value="value" :disabled="disabled" @input="$emit('update:value', $event.target.value)"></textarea></template>
      </div>`,
    data() { return {locale: 'de-DE', newLocale: '', defaults: defaultIndexFields}; },
    methods: {
        saveFields(fields) { this.$emit('update:value', JSON.stringify({...this.parsed.data, [this.locale]: fields})); },
        removeOverride() { const next = {...this.parsed.data}; delete next[this.locale]; this.$emit('update:value', JSON.stringify(next)); },
        addLocale() { if (!this.localeValid) return; this.locale = this.newLocale; this.newLocale = ''; },
    },
});

Shopware.Component.register('sw-meilisearch-quantity-units', {
    inject: ['repositoryFactory'],
    props: {value: {type: String, default: ''}, disabled: {type: Boolean, default: false}},
    emits: ['update:value'],
    data() { return {unitId: null, symbol: '', factor: 1, unitError: ''}; },
    computed: {
        parsed() {
            const x = parseSetting(this.value, {}, 'object');
            if (x.valid && Object.entries(x.data).some(([id, item]) => !/^[a-f0-9]{32}$/.test(id) || !item || typeof item !== 'object' || Array.isArray(item) || Object.keys(item).length !== 2 || !['ml', 'l', 'g', 'kg'].includes(item.symbol) || typeof item.factor !== 'number' || !Number.isFinite(item.factor) || item.factor <= 0 || item.factor > 1000000)) x.valid = false;
            return x;
        },
        entries() { return Object.entries(this.parsed.data).map(([id, unit]) => ({id, ...unit})); },
        canAdd() { return !this.disabled && this.unitId && /^[a-f0-9]{32}$/.test(this.unitId) && ['ml', 'l', 'g', 'kg'].includes(this.symbol) && Number.isFinite(Number(this.factor)) && Number(this.factor) > 0 && Number(this.factor) <= 1000000 && !Object.hasOwn(this.parsed.data, this.unitId) && this.entries.length < 32; },
    },
    template: `
      <div class="sw-meilisearch-config">
        <template v-if="parsed.valid">
          <p class="sw-meilisearch-config__help">Ordne vorhandene Shopware-Einheiten einem Suchsymbol zu. Mengen werden für die Suche auch zwischen ml und l sowie g und kg umgerechnet.</p>
          <p v-if="!entries.length" class="sw-meilisearch-config__help">Keine Einheiten zugeordnet. Die normale Suche funktioniert weiterhin.</p>
          <div v-for="entry in entries" :key="entry.id" class="sw-meilisearch-config__item sw-meilisearch-config__unit">
            <sw-entity-single-select entity="unit" :value="entry.id" :disabled="true" label="Shopware-Einheit" />
            <span>1 Einheit = {{ entry.factor }} {{ entry.symbol }}</span>
            <sw-button :disabled="disabled" :aria-label="'Einheitenzuordnung entfernen'" @click="remove(entry.id)">Entfernen</sw-button>
          </div>
          <div class="sw-meilisearch-config__unit-add">
            <sw-entity-single-select entity="unit" :value="unitId" :disabled="disabled" label="Shopware-Einheit" @update:value="selectUnit" />
            <label>Suchsymbol <select v-model="symbol" :disabled="disabled" class="sw-meilisearch-config__input"><option value="">Auswählen</option><option value="ml">Milliliter (ml)</option><option value="l">Liter (l)</option><option value="g">Gramm (g)</option><option value="kg">Kilogramm (kg)</option></select></label>
            <label>Faktor <input v-model.number="factor" :disabled="disabled" type="number" min="0.000001" max="1000000" step="any" class="sw-meilisearch-config__input"></label>
            <sw-button :disabled="!canAdd" @click="add">Zuordnung hinzufügen</sw-button>
          </div>
          <p class="sw-meilisearch-config__help">Der Faktor gibt an, wie viele gewählte Suchsymbole einer Shopware-Einheit entsprechen. Bei Liter → l oder Milliliter → ml ist er 1. Änderungen speichern und den Index neu aufbauen.</p>
          <p v-if="unitId && parsed.data[unitId]" class="sw-meilisearch-config__help">Diese Einheit ist bereits zugeordnet. Zum Ändern erst die alte Zuordnung entfernen.</p>
          <p v-if="unitError" role="alert" class="sw-meilisearch-config__error">{{ unitError }}</p>
        </template>
        <template v-else><p role="alert" class="sw-meilisearch-config__error">Die bestehende Einstellung ist ungültig. Bitte korrigieren; sie wird nicht automatisch überschrieben.</p><textarea class="sw-meilisearch-config__input" :value="value" :disabled="disabled" @input="$emit('update:value', $event.target.value)"></textarea></template>
      </div>`,
    methods: {
        async selectUnit(id) {
            this.unitId = id; this.symbol = ''; this.factor = 1; this.unitError = '';
            if (!id) return;
            try {
                const unit = await this.repositoryFactory.create('unit').get(id, Shopware.Context.api);
                if (this.unitId !== id) return;
                const code = String(unit?.translated?.shortCode || unit?.shortCode || '').trim();
                const symbols = {'ml': 'ml', 'mL': 'ml', 'l': 'l', 'L': 'l', 'g': 'g', 'kg': 'kg'};
                if (Object.hasOwn(symbols, code)) this.symbol = symbols[code];
            } catch (_) { if (this.unitId === id) this.unitError = 'Die Einheit konnte nicht geladen werden. Bitte Berechtigungen prüfen.'; }
        },
        add() { if (!this.canAdd) return; this.$emit('update:value', JSON.stringify({...this.parsed.data, [this.unitId]: {symbol: this.symbol, factor: Number(this.factor)}})); this.unitId = null; this.symbol = ''; this.factor = 1; },
        remove(id) { const next = {...this.parsed.data}; delete next[id]; this.$emit('update:value', JSON.stringify(next)); },
    },
});

Shopware.Component.register('sw-meilisearch-query-suggestions', {
    props: {value: {type: String, default: ''}, disabled: {type: Boolean, default: false}},
    emits: ['update:value'],
    data() { return {locale: 'de-DE', newLocale: ''}; },
    computed: {
        parsed() { const x = parseSetting(this.value, {}, 'object'); if (x.valid && Object.values(x.data).some(v => !Array.isArray(v) || v.some(t => typeof t !== 'string'))) x.valid = false; return x; },
        locales() { return [...new Set([...localeKeys(this.parsed.data), this.locale])]; },
        terms() { return (this.parsed.data[this.locale] || []).join('\n'); },
        localeValid() { return /^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})+$/.test(this.newLocale); },
    },
    template: `
      <div class="sw-meilisearch-config">
        <template v-if="parsed.valid">
          <p class="sw-meilisearch-config__help">Diese Vorschläge erscheinen beim Öffnen der Suche. Je Sprache werden höchstens zehn Einträge angezeigt.</p>
          <label>Sprache auswählen <select v-model="locale" class="sw-meilisearch-config__input"><option v-for="item in locales" :key="item" :value="item">{{ item }}</option></select></label>
          <label>Ein Suchvorschlag pro Zeile <textarea :value="terms" :disabled="disabled" rows="4" class="sw-meilisearch-config__input" @input="setTerms($event.target.value)"></textarea></label>
          <div class="sw-meilisearch-config__add-row"><label>Weitere Sprachkennung <input v-model.trim="newLocale" :disabled="disabled" class="sw-meilisearch-config__input" placeholder="z. B. nl-NL"></label><sw-button :disabled="disabled || !localeValid" @click="addLocale">Sprache auswählen</sw-button></div>
        </template>
        <template v-else><p role="alert" class="sw-meilisearch-config__error">Die bestehende Einstellung ist kein gültiges JSON. Bitte korrigieren; sie wird nicht automatisch überschrieben.</p><textarea class="sw-meilisearch-config__input" :value="value" :disabled="disabled" @input="$emit('update:value', $event.target.value)"></textarea></template>
      </div>`,
    methods: {
        setTerms(text) { this.$emit('update:value', JSON.stringify({...this.parsed.data, [this.locale]: text === '' ? [] : text.split('\n')})); },
        addLocale() { if (!this.localeValid) return; this.locale = this.newLocale; this.newLocale = ''; },
    },
});

Shopware.Component.register('sw-meilisearch-featured-products', {
    props: {value: {type: String, default: ''}, disabled: {type: Boolean, default: false}},
    emits: ['update:value'],
    data() { return {locale: 'de-DE', newLocale: '', productId: null}; },
    computed: {
        parsed() {
            const x = parseSetting(this.value, {}, 'object');
            if (x.valid && Object.values(x.data).some(ids => !Array.isArray(ids) || ids.length > 10 || new Set(ids).size !== ids.length || ids.some(id => typeof id !== 'string' || !/^[a-f0-9]{32}$/.test(id)))) x.valid = false;
            return x;
        },
        locales() { return [...new Set([...localeKeys(this.parsed.data), this.locale])]; },
        products() { return this.parsed.data[this.locale] || []; },
        localeValid() { return /^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})+$/.test(this.newLocale); },
        canAdd() { return !this.disabled && this.productId && !this.products.includes(this.productId) && this.products.length < 10; },
    },
    template: `
      <div class="sw-meilisearch-config">
        <template v-if="parsed.valid">
          <p class="sw-meilisearch-config__help">Diese Produkte erscheinen beim Öffnen der Suche, soweit sie im Verkaufskanal sichtbar sind. Je Sprache können bis zu zehn Produkte ausgewählt werden. Änderungen speichern.</p>
          <label>Sprache auswählen <select v-model="locale" class="sw-meilisearch-config__input" @change="productId = null"><option v-for="item in locales" :key="item" :value="item">{{ item }}</option></select></label>
          <div v-for="id in products" :key="id" class="sw-meilisearch-config__item sw-meilisearch-config__unit">
            <sw-entity-single-select entity="product" :value="id" :disabled="true" label="Startprodukt" />
            <sw-button :disabled="disabled" aria-label="Startprodukt entfernen" @click="remove(id)">Entfernen</sw-button>
          </div>
          <div class="sw-meilisearch-config__unit-add">
            <sw-entity-single-select entity="product" :value="productId" :disabled="disabled || products.length >= 10" label="Produkt auswählen" @update:value="productId = $event" />
            <sw-button :disabled="!canAdd" @click="add">Produkt hinzufügen</sw-button>
          </div>
          <p v-if="products.length >= 10" class="sw-meilisearch-config__help">Für diese Sprache sind bereits zehn Produkte ausgewählt.</p>
          <p v-else-if="productId && products.includes(productId)" class="sw-meilisearch-config__help">Dieses Produkt ist bereits ausgewählt.</p>
          <div class="sw-meilisearch-config__add-row"><label>Weitere Sprachkennung <input v-model.trim="newLocale" :disabled="disabled" class="sw-meilisearch-config__input" placeholder="z. B. nl-NL"></label><sw-button :disabled="disabled || !localeValid" @click="addLocale">Sprache auswählen</sw-button></div>
        </template>
        <template v-else><p role="alert" class="sw-meilisearch-config__error">Die bestehende Einstellung ist ungültig oder enthält mehr als zehn Produkte je Sprache. Bitte korrigieren; sie wird nicht automatisch überschrieben.</p><textarea class="sw-meilisearch-config__input" :value="value" :disabled="disabled" @input="$emit('update:value', $event.target.value)"></textarea></template>
      </div>`,
    methods: {
        add() { if (!this.canAdd) return; this.$emit('update:value', JSON.stringify({...this.parsed.data, [this.locale]: [...this.products, this.productId]})); this.productId = null; },
        remove(id) { if (this.disabled) return; this.$emit('update:value', JSON.stringify({...this.parsed.data, [this.locale]: this.products.filter(product => product !== id)})); },
        addLocale() { if (!this.localeValid) return; this.locale = this.newLocale; this.newLocale = ''; this.productId = null; },
    },
});
