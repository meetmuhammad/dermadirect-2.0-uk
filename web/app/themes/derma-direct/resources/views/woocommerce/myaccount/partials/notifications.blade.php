@php
    $siteNotifications = ddce_get_active_notifications();
@endphp

@if(!empty($siteNotifications))
    <div class="mb-6">
        <h2 class="text-2xl font-semibold">
            Notifications
        </h2>
        <p class="text-gray-600">
            Stay updated with your latest account activity.
        </p>
    </div>

    <div class="space-y-4">
        @foreach($siteNotifications as $notification)
            <div class="flex items-start gap-4 rounded-md border {{ ddce_notification_type_classes($notification['type']) }} p-5">
                <div>
                    @if(!empty($notification['title']))
                        <h3 class="font-semibold">
                            {{ esc_html($notification['title']) }}
                        </h3>
                    @endif
                    @if(!empty($notification['content']))
                        <p class="mt-1 text-sm text-gray-700">
                            {{ esc_html($notification['content']) }}
                        </p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif