<section id="learning-consent">
    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('learning.title') }}</h2>
    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('learning.purpose') }}</p>
    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('learning.withdrawal') }}</p>
    <form method="post" action="{{ route('profile.learning-consent') }}" class="mt-4 space-y-4">
        @csrf
        @method('put')
        <input type="hidden" name="version" value="{{ config('learning_engine.consent_version') }}">
        <input type="hidden" name="consent" value="0">
        <label class="flex gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" name="consent" value="1" @checked($user->learning_consent_at && $user->learning_consent_version === config('learning_engine.consent_version'))>
            <span>{{ __('learning.accept') }}</span>
        </label>
        <x-primary-button>{{ __('Save') }}</x-primary-button>
        @if (session('status') === 'learning-consent-updated')
            <p role="status">{{ __('learning.saved') }}</p>
        @endif
        <x-input-error :messages="$errors->get('consent')" />
    </form>
</section>
