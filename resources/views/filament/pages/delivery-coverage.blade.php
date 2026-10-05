<x-filament-panels::page>
    @once
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script>
            window.deliveryCoverageMap = (latitude, longitude, radius) => ({
                latitude,
                longitude,
                radius,
                init() {
                    this.map = L.map(this.$refs.map).setView([this.latitude, this.longitude], 13);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; OpenStreetMap contributors',
                    }).addTo(this.map);
                    this.marker = L.marker([this.latitude, this.longitude], { draggable: true }).addTo(this.map);
                    this.circle = L.circle([this.latitude, this.longitude], {
                        radius: this.radius * 1000,
                        color: '#0f766e',
                        fillColor: '#14b8a6',
                        fillOpacity: 0.16,
                        weight: 2,
                    }).addTo(this.map);

                    this.marker.on('dragend', () => this.setLocation(this.marker.getLatLng()));
                    this.map.on('click', (event) => this.setLocation(event.latlng));
                    this.$watch('radius', (value) => this.circle.setRadius(Number(value) * 1000));
                },
                setLocation(location) {
                    this.latitude = Number(location.lat.toFixed(6));
                    this.longitude = Number(location.lng.toFixed(6));
                    this.marker.setLatLng([this.latitude, this.longitude]);
                    this.circle.setLatLng([this.latitude, this.longitude]);
                },
            });
        </script>
    @endonce

    <div class="space-y-5" x-data="deliveryCoverageMap(@js($storeLat), @js($storeLng), @js($deliveryRadius))">
        <section class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_19rem]">
            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <div>
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Lokasi toko dan area layanan</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Pilih titik toko pada peta untuk melihat jangkauan pengantaran.</p>
                    </div>
                    <span class="rounded-md bg-teal-50 px-2.5 py-1 text-sm font-medium text-teal-800 dark:bg-teal-400/10 dark:text-teal-300">
                        Radius <span x-text="Number(radius).toLocaleString('id-ID')"></span> km
                    </span>
                </div>
                <div x-ref="map" class="z-0 w-full" style="height: min(65vh, 560px); min-height: 360px; width: 100%;" aria-label="Peta jangkauan pengiriman"></div>
            </div>

            <aside class="space-y-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Titik toko</h2>

                <label class="block space-y-1.5">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Latitude</span>
                    <input type="number" step="0.000001" min="-90" max="90" x-model.number="latitude" @input="setLocation({ lat: latitude, lng: longitude })" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                </label>

                <label class="block space-y-1.5">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Longitude</span>
                    <input type="number" step="0.000001" min="-180" max="180" x-model.number="longitude" @input="setLocation({ lat: latitude, lng: longitude })" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                </label>

                <label class="block space-y-2">
                    <span class="flex items-center justify-between text-sm font-medium text-gray-700 dark:text-gray-300">
                        <span>Radius pengiriman</span>
                        <span x-text="`${Number(radius).toLocaleString('id-ID')} km`"></span>
                    </span>
                    <input type="range" min="0.5" max="20" step="0.5" x-model.number="radius" class="w-full accent-teal-700">
                    <input type="number" min="0.5" max="100" step="0.5" x-model.number="radius" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                </label>

                <button type="button" wire:click="saveSettings(latitude, longitude, radius)" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50" wire:loading.attr="disabled">
                    <x-filament::icon icon="heroicon-m-map-pin" class="h-4 w-4" />
                    Simpan jangkauan
                </button>
            </aside>
        </section>
    </div>
</x-filament-panels::page>
