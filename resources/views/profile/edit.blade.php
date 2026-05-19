<x-app-layout>
    <x-page-header title="Profile" subtitle="Manage your account settings and personal information." />

    <div class="max-w-2xl space-y-6">
        <div class="bg-iot-surface border border-iot-border rounded-2xl p-6 sm:p-8">
            @include('profile.partials.update-profile-information-form')
        </div>

        <div class="bg-iot-surface border border-iot-border rounded-2xl p-6 sm:p-8">
            @include('profile.partials.update-password-form')
        </div>

        <div class="bg-iot-surface border border-iot-border rounded-2xl p-6 sm:p-8">
            @include('profile.partials.delete-user-form')
        </div>
    </div>
</x-app-layout>
