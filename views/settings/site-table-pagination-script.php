<?php

declare(strict_types=1);

// EN: Filter before paging and retain every form control, including unsaved edits and off-page selections.
// Navigation never submits or starts an import; draft serialization still reads the entire form.
//
// HR: Filtriraj prije listanja i zadrži sva polja, uključujući nespremljene unose i odabire s drugih stranica.
// Navigacija ne šalje obrazac niti pokreće uvoz; spremanje nacrta i dalje čita cijeli obrazac.
?>
    const createSiteTablePager = (form, rowSelector, matches, checkboxSelector = '') => {
        if (!form) return null;
        const rows = Array.from(form.querySelectorAll(rowSelector));
        const controls = Array.from(form.querySelectorAll('[data-site-table-pagination]'));
        let page = 1, size = 25, filtered = [];
        const format = (text, values) => text.replace(/\{(\w+)\}/g, (_, key) => values[key]);
        const render = () => {
            filtered = rows.filter(matches);
            const pages = Math.max(1, Math.ceil(filtered.length / size));
            page = Math.max(1, Math.min(page, pages));
            const visible = new Set(filtered.slice((page - 1) * size, page * size));
            rows.forEach((row) => { row.classList.toggle('d-none', !visible.has(row)); });
            const numbers = new Set([1, pages]);
            for (let number = Math.max(1, page - 2); number <= Math.min(pages, page + 2); number += 1) numbers.add(number);
            const selected = checkboxSelector ? rows.filter((row) => row.querySelector(checkboxSelector)?.checked).length : null;
            controls.forEach((control) => {
                const labels = control.dataset;
                control.querySelector('[data-pager-size]').value = String(size);
                control.querySelector('[data-pager-count]').textContent = format(labels.countLabel, {
                    from: filtered.length ? (page - 1) * size + 1 : 0, to: Math.min(page * size, filtered.length), total: filtered.length,
                });
                const selection = control.querySelector('[data-pager-selected]');
                selection.hidden = selected === null;
                selection.textContent = selected === null ? '' : format(labels.selectedLabel, {selected, all: rows.length});
                const list = control.querySelector('[data-pager-pages]');
                list.replaceChildren();
                list.parentElement.hidden = pages === 1;
                const add = (number, text, label, disabled = false) => {
                    const item = document.createElement('li');
                    item.className = 'page-item' + (disabled ? ' disabled' : (number === page ? ' active' : ''));
                    const link = document.createElement(disabled || number === page ? 'span' : 'button');
                    link.className = 'page-link';
                    link.textContent = text;
                    if (label) { link.setAttribute('aria-label', label); link.title = label; }
                    if (disabled) link.setAttribute('aria-disabled', 'true');
                    else if (number === page) link.setAttribute('aria-current', 'page');
                    else {
                        link.type = 'button';
                        link.dataset.pagerPage = String(number);
                    }
                    item.append(link);
                    list.append(item);
                };
                add(1, '«', labels.firstLabel, page === 1);
                add(page - 1, '‹', labels.previousLabel, page === 1);
                let previous = 0;
                Array.from(numbers).sort((left, right) => left - right).forEach((number) => {
                    if (previous && number - previous > 1) add(null, '…', '', true);
                    add(number, String(number), labels.pageLabel.replace('%d', String(number)));
                    previous = number;
                });
                add(page + 1, '›', labels.nextLabel, page === pages);
                add(pages, '»', labels.lastLabel, page === pages);
            });
            form.querySelectorAll('[data-pager-empty]').forEach((message) => { message.hidden = filtered.length !== 0; });
        };
        controls.forEach((control) => {
            control.querySelector('[data-pager-size]').addEventListener('change', (event) => {
                size = Number(event.target.value);
                page = 1;
                render();
            });
            control.addEventListener('click', (event) => {
                const button = event.target.closest('[data-pager-page]');
                if (!button) return;
                const keepFocus = button.getAttribute('aria-label');
                page = Number(button.dataset.pagerPage);
                render();
                const focus = Array.from(control.querySelectorAll('[data-pager-page]')).find((link) => link.getAttribute('aria-label') === keepFocus);
                (focus || control.querySelector('[data-pager-size]')).focus({preventScroll: true});
            });
        });
        form.addEventListener('change', (event) => {
            if (checkboxSelector && event.target.matches(checkboxSelector)) render();
        });
        render();
        return {
            refresh: (reset = false) => { if (reset) page = 1; render(); },
            matchingRows: () => rows.filter(matches),
            reveal: (row) => { page = Math.floor(filtered.indexOf(row) / size) + 1; render(); },
        };
    };
