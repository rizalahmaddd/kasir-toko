/**
 * Copies each <thead> column label onto the matching body cell as data-label, which the
 * .table-stack CSS shows as the "label" of each line when a table reflows into cards on phones.
 * Cells that already carry data-label (or span several columns) are left untouched.
 */
function labelStackedTables() {
    document.querySelectorAll('table.table-stack').forEach((table) => {
        const labels = [...table.querySelectorAll('thead th')].map((th) => th.textContent.trim());

        table.querySelectorAll('tbody tr, tfoot tr').forEach((row) => {
            let column = 0;

            [...row.children].forEach((cell) => {
                const span = Number(cell.getAttribute('colspan')) || 1;

                if (span === 1 && !cell.hasAttribute('data-label')) {
                    cell.setAttribute('data-label', labels[column] ?? '');
                }

                column += span;
            });
        });
    });
}

let scheduled = false;

function scheduleLabelling() {
    if (scheduled) {
        return;
    }

    scheduled = true;
    requestAnimationFrame(() => {
        scheduled = false;
        labelStackedTables();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    labelStackedTables();

    // Livewire morphs rows in place (sorting, paging, search), which can drop the attribute.
    new MutationObserver(scheduleLabelling).observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['data-label'],
    });
});
document.addEventListener('livewire:navigated', labelStackedTables);
