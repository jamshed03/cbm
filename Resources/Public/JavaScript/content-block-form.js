/**
 * Form to create a Content Block: add and remove field rows, show the items of Select/Radio fields, put in the
 * options of the chosen field type, and show the inputs that only apply to the chosen content type.
 */
const form = document.querySelector('[data-cbm-create]')

if (form) {
    const fieldList = form.querySelector('[data-cbm-fields]')
    const fieldTemplate = form.querySelector('template[data-cbm-field-template]')
    const typesWithItems = form.dataset.cbmTypesWithItems.split(',')
    const contentType = form.querySelector('[data-cbm-content-type]')
    let nextIndex = fieldList.querySelectorAll('[data-cbm-field]').length

    const toggleItems = (row) => {
        row.querySelector('[data-cbm-items]').hidden = !typesWithItems.includes(row.querySelector('[data-cbm-type]').value)
    }
    // Rows are rendered with the options of their field type only; another type gets its options from its template.
    const replaceOptions = (row) => {
        const template = form.querySelector(`template[data-cbm-options-template="${row.querySelector('[data-cbm-type]').value}"]`)
        row.querySelector('[data-cbm-options]').innerHTML = template
            ? template.innerHTML.replaceAll('__INDEX__', row.dataset.cbmIndex)
            : ''
    }
    const toggleContentType = () => {
        form.querySelectorAll('[data-cbm-only]').forEach((element) => {
            element.hidden = !element.dataset.cbmOnly.split(',').includes(contentType.value)
        })
    }

    form.addEventListener('click', (event) => {
        if (event.target.closest('[data-cbm-add-field]')) {
            fieldList.insertAdjacentHTML('beforeend', fieldTemplate.innerHTML.replaceAll('__INDEX__', String(nextIndex++)))
            toggleItems(fieldList.lastElementChild)
            replaceOptions(fieldList.lastElementChild)
        }
        const removeButton = event.target.closest('[data-cbm-remove-field]')
        if (removeButton) {
            removeButton.closest('[data-cbm-field]').remove()
        }
    })
    form.addEventListener('change', (event) => {
        if (event.target.matches('[data-cbm-type]')) {
            const row = event.target.closest('[data-cbm-field]')
            toggleItems(row)
            replaceOptions(row)
        }
    })
    contentType.addEventListener('change', toggleContentType)
    fieldList.querySelectorAll('[data-cbm-field]').forEach(toggleItems)
    toggleContentType()
}
