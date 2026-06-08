import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

const initialCharacterization = window.App?.characterization ?? {};

Alpine.store('characterization', {
    status: initialCharacterization.status ?? 'draft',
    submittedAt: initialCharacterization.submitted_at ?? null,
    completedAt: initialCharacterization.completed_at ?? null,
    lastError: initialCharacterization.last_error ?? null,
    resultData: initialCharacterization.result_data ?? null,
    retryCount: initialCharacterization.retry_count ?? 0,
    nextRetryAt: initialCharacterization.next_retry_at ?? null,
    lastJobAttemptedAt: initialCharacterization.last_job_attempted_at ?? null,
    update(payload) {
        if (!payload) {
            return;
        }

        this.status = payload.status ?? this.status;
        this.submittedAt = payload.submitted_at ?? this.submittedAt;
        this.completedAt = payload.completed_at ?? this.completedAt;
        this.lastError = payload.last_error ?? null;
        this.resultData = payload.result_data ?? this.resultData;
        this.retryCount = payload.retry_count ?? this.retryCount;
        this.nextRetryAt = payload.next_retry_at ?? this.nextRetryAt;
        this.lastJobAttemptedAt = payload.last_job_attempted_at ?? this.lastJobAttemptedAt;
    },
    label() {
        const labels = window.App?.statusLabels ?? {};
        return labels[this.status] ?? this.status;
    },
    badgeClass() {
        const map = window.App?.statusClasses ?? {};
        const base = map.default ?? 'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300';
        const specific = map[this.status];
        return `${base} ${specific ?? ''}`.trim();
    },
});

const registerCharacterizationChannel = () => {
    if (!window.Echo || !window.App?.userId) {
        return;
    }

    window.Echo.private(`characterizations.${window.App.userId}`)
        .listen('CharacterizationStatusUpdated', (event) => {
            Alpine.store('characterization').update(event);
        });
};

registerCharacterizationChannel();

const debounce = (callback, delay = 300) => {
    let timeout;
    return (...args) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => callback.apply(null, args), delay);
    };
};

Alpine.data('NacePicker', (config = {}) => ({
    locale: config.locale || 'en',
    selectedOption: config.selected || null,
    selectedCode: config.selected?.code || '',
    search: '',
    options: [],
    meta: null,
    isLoading: false,
    perPage: 50,
    async init() {
        await this.loadOptions();
    },
    async loadOptions(page = 1) {
        this.isLoading = true;
        const params = new URLSearchParams({
            per_page: this.perPage,
            page: String(page),
        });

        if (this.search.trim()) {
            params.append('search', this.search.trim());
        }

        const response = await fetch(`/api/nace-codes?${params.toString()}`);
        const json = await response.json();

        this.meta = json.meta;

        if (page === 1) {
            this.options = json.data;
        } else {
            this.options = this.options.concat(json.data);
        }

        this.ensureSelectedIncluded();
        this.isLoading = false;
    },
    ensureSelectedIncluded() {
        if (!this.selectedOption) {
            return;
        }

        const exists = this.options.some(option => option.code === this.selectedOption.code);
        if (!exists) {
            this.options.unshift(this.selectedOption);
        }
    },
    filter: debounce(function () {
        this.loadOptions(1);
    }),
    loadMore() {
        if (this.meta && this.meta.current_page < this.meta.last_page) {
            this.loadOptions(this.meta.current_page + 1);
        }
    },
    displayLabel(option) {
        const title = option.title?.[this.locale] ?? option.title?.en ?? '';
        return `${option.code} - ${title}`.trim();
    },
    isSelected(code) {
        return String(this.selectedCode) === String(code);
    },
    updateSelected(code) {
        this.selectedCode = code;
        const found = this.options.find(option => option.code === code);
        if (found) {
            this.selectedOption = found;
        }
    },
}));

Alpine.data('EsrsPicker', (config = {}) => ({
    locale: config.locale || 'en',
    selected: config.selected || [],
    selectedIds: (config.selected || []).map(option => option.id),
    search: '',
    perPage: 50,
    meta: null,
    topics: [],
    groups: [],
    isLoading: false,
    async init() {
        this.mergeSelected();
        await this.loadOptions();
    },
    async loadOptions(page = 1) {
        this.isLoading = true;
        const params = new URLSearchParams({
            per_page: this.perPage,
            page: String(page),
        });

        if (this.search.trim()) {
            params.append('search', this.search.trim());
        }

        const response = await fetch(`/api/esrs-topics?${params.toString()}`);
        const json = await response.json();

        this.meta = json.meta;

        const incoming = json.data;

        if (page === 1) {
            this.topics = [];
        }

        incoming.forEach(topic => {
            if (!this.topics.some(existing => existing.id === topic.id)) {
                this.topics.push(topic);
            }
        });

        this.mergeSelected();
        this.buildGroups();
        this.isLoading = false;
    },
    mergeSelected() {
        this.selected.forEach(topic => {
            if (!this.topics.some(existing => existing.id === topic.id)) {
                this.topics.push(topic);
            }
        });
        this.buildGroups();
    },
    buildGroups() {
        const grouped = {};
        this.topics.forEach(topic => {
            const themeLabel = topic.theme?.[this.locale] ?? topic.theme?.en ?? '';
            const key = `${topic.esrs_code} - ${themeLabel}`.trim();
            if (!grouped[key]) {
                grouped[key] = {
                    label: key,
                    options: [],
                };
            }
            grouped[key].options.push(topic);
        });

        this.groups = Object.values(grouped).map(group => {
            group.options.sort((a, b) => {
                const aLabel = a.subtheme?.[this.locale] ?? a.subtheme?.en ?? '';
                const bLabel = b.subtheme?.[this.locale] ?? b.subtheme?.en ?? '';
                return aLabel.localeCompare(bLabel);
            });
            return group;
        });
    },
    filter: debounce(function () {
        this.loadOptions(1);
    }),
    loadMore() {
        if (this.meta && this.meta.current_page < this.meta.last_page) {
            this.loadOptions(this.meta.current_page + 1);
        }
    },
    get hasMore() {
        return this.meta && this.meta.current_page < this.meta.last_page;
    },
}));

Alpine.start();
