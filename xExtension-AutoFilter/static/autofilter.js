// AutoFilter extension - main JavaScript

(function () {
    'use strict';

    // Добавление кнопки проверки записи
    function addCheckButton() {
        const entries = document.querySelectorAll('.flux');
        
        entries.forEach(entry => {
            const entryId = entry.getAttribute('data-id');
            if (!entryId) return;

            let actions = entry.querySelector('.flux_header .flux_header_actions');
            if (!actions) {
                actions = document.createElement('div');
                actions.className = 'flux_header_actions';
                const header = entry.querySelector('.flux_header');
                if (header) {
                    header.appendChild(actions);
                }
            }

            // Проверяем, есть ли уже кнопка
            if (actions.querySelector('.autofilter-check-btn')) {
                return;
            }

            const btn = document.createElement('button');
            btn.className = 'btn autofilter-check-btn';
            btn.title = 'Проверить на рекламу';
            btn.innerHTML = '🛡️';
            btn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                checkEntry(entryId, entry);
            };

            actions.appendChild(btn);
        });
    }

    // Проверка записи через API
    async function checkEntry(entryId, entryElement) {
        const btn = entryElement.querySelector('.autofilter-check-btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '⏳';
        }

        try {
            const response = await fetch('./api/p.php?c=autoFilter_openrouter&a=checkEntry&entry_id=' + entryId, {
                method: 'GET',
                credentials: 'same-origin',
            });

            const result = await response.json();

            if (result.success) {
                updateEntryLabels(entryElement, result.analysis);
                showNotification('Проверка завершена: ' + getLabelName(result.analysis.label));
            } else {
                showNotification('Ошибка: ' + (result.error || 'Неизвестная ошибка'), 'error');
            }
        } catch (error) {
            showNotification('Ошибка при проверке: ' + error.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '🛡️';
            }
        }
    }

    // Обновление визуальных меток на записи
    function updateEntryLabels(entryElement, analysis) {
        // Удаляем старые классы
        entryElement.classList.remove('autofilter-ad', 'autofilter-possible-ad');

        // Добавляем новые классы
        if (analysis.label === 'advertisement') {
            entryElement.classList.add('autofilter-ad');
        } else if (analysis.label === 'possible_advertisement') {
            entryElement.classList.add('autofilter-possible-ad');
        }

        // Добавляем tooltip с информацией
        const titleElement = entryElement.querySelector('.flux_header .flux_title');
        if (titleElement) {
            const confidencePercent = Math.round((analysis.confidence || 0) * 100);
            titleElement.title = `AI Анализ: ${analysis.reason} (Уверенность: ${confidencePercent}%)`;
        }
    }

    // Получение названия метки на русском
    function getLabelName(label) {
        const labels = {
            'advertisement': 'Реклама',
            'possible_advertisement': 'Возможно реклама',
            'none': 'Не реклама',
        };
        return labels[label] || label;
    }

    // Показ уведомления
    function showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `alert alert-${type}`;
        notification.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.textContent = message;

        document.body.appendChild(notification);

        setTimeout(() => {
            notification.remove();
        }, 5000);
    }

    // Проверка массовых действий
    function addBulkActions() {
        const bulkActions = document.querySelector('.bulk_actions');
        if (!bulkActions) return;

        if (bulkActions.querySelector('.autofilter-bulk-check')) {
            return;
        }

        const checkBtn = document.createElement('button');
        checkBtn.className = 'btn autofilter-bulk-check';
        checkBtn.textContent = '🛡️ Проверить выбранные';
        checkBtn.onclick = (e) => {
            e.preventDefault();
            checkBulkEntries();
        };

        bulkActions.insertBefore(checkBtn, bulkActions.firstChild);
    }

    // Массовая проверка записей
    async function checkBulkEntries() {
        const selectedEntries = document.querySelectorAll('.flux:checked-input, .flux.active');
        if (selectedEntries.length === 0) {
            showNotification('Выберите записи для проверки', 'error');
            return;
        }

        showNotification(`Проверка ${selectedEntries.length} записей...`);

        for (const entry of selectedEntries) {
            const entryId = entry.getAttribute('data-id');
            if (entryId) {
                await checkEntry(entryId, entry);
                await sleep(100); // Небольшая задержка между запросами
            }
        }

        showNotification('Массовая проверка завершена');
    }

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    // Инициализация после загрузки страницы
    document.addEventListener('DOMContentLoaded', function() {
        addCheckButton();
        addBulkActions();
    });

    // Перезапуск при обновлении ленты (для динамической подгрузки)
    if (typeof window.addEventListener === 'function') {
        window.addEventListener('freshrss:feed-loaded', function() {
            setTimeout(() => {
                addCheckButton();
                addBulkActions();
            }, 500);
        });
    }
})();
