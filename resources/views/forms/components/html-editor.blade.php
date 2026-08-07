<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="{
            state: $wire.$entangle(@js($getStatePath())),
            editor: null,
            init() {
                window.initHtmlEditor(this, {
                    minHeight: @js($getMinHeight()),
                    uploadUrl: @js(route('admin.editor.upload')),
                    csrf: @js(csrf_token()),
                    baseUrl: @js(asset('vendor/tinymce')),
                    languageUrl: @js(asset('vendor/tinymce/langs/ru.js')),
                })
            },
            destroy() {
                this.editor?.remove()
            },
        }"
        class="rounded-lg border border-gray-950/10 dark:border-white/20 overflow-hidden"
    >
        <textarea x-ref="editor" class="hidden">{{ $getState() }}</textarea>
    </div>
</x-dynamic-component>

@once
    <script>
        // TinyMCE лежит локально (public/vendor/tinymce) и грузится один раз на страницу.
        window.initHtmlEditor = async function (component, config) {
            window.__tinymcePromise ??= new Promise((resolve, reject) => {
                const script = document.createElement('script')
                script.src = config.baseUrl + '/tinymce.min.js'
                script.onload = resolve
                script.onerror = reject
                document.head.appendChild(script)
            })

            await window.__tinymcePromise

            const dark = document.documentElement.classList.contains('dark')

            const editors = await tinymce.init({
                target: component.$refs.editor,
                license_key: 'gpl',
                base_url: config.baseUrl,
                suffix: '.min',
                language: 'ru',
                language_url: config.languageUrl,
                promotion: false,
                branding: false,
                menubar: false,
                elementpath: false,
                skin: dark ? 'oxide-dark' : 'oxide',
                content_css: dark ? 'dark' : 'default',
                plugins: 'advlist autolink lists link image charmap searchreplace visualblocks code fullscreen table wordcount autoresize preview',
                toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link image table | removeformat | searchreplace visualblocks code fullscreen',
                block_formats: 'Абзац=p; Заголовок=h2; Подзаголовок=h3; Мелкий заголовок=h4',
                // Ничего не вычищаем: в тексте страниц лежит разметка темы (div-ы
                // аккордеона, классы), и любая «нормализация» её ломает.
                valid_elements: '*[*]',
                extended_valid_elements: '*[*]',
                verify_html: false,
                cleanup: false,
                convert_urls: false,
                entity_encoding: 'raw',
                autoresize_bottom_margin: 24,
                min_height: config.minHeight,
                content_style: 'body{font-family:system-ui,sans-serif;font-size:15px;line-height:1.6;padding:12px}',
                images_upload_handler: (blobInfo) => new Promise((resolve, reject) => {
                    const data = new FormData()
                    data.append('file', blobInfo.blob(), blobInfo.filename())

                    fetch(config.uploadUrl, {
                        method: 'POST',
                        body: data,
                        headers: { 'X-CSRF-TOKEN': config.csrf, 'Accept': 'application/json' },
                    })
                        .then((response) => response.ok ? response.json() : Promise.reject(response.statusText))
                        .then((json) => resolve(json.location))
                        .catch(reject)
                }),
                setup: (editor) => {
                    component.editor = editor

                    // Держим состояние формы в актуальном виде на каждое изменение,
                    // иначе «Сохранить» отправит текст, каким он был при открытии.
                    const push = () => {
                        component.state = editor.getContent()
                    }

                    editor.on('change input undo redo blur', push)
                },
            })

            return editors[0]
        }
    </script>
@endonce
