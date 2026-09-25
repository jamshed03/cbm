/**
 * Form to create a Content Block: add and remove field rows, show the items of Select/Radio fields and the inputs
 * that only apply to the chosen content type.
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
    const toggleContentType = () => {
        form.querySelectorAll('[data-cbm-only]').forEach((element) => {
            element.hidden = !element.dataset.cbmOnly.split(',').includes(contentType.value)
        })
    }

    form.addEventListener('click', (event) => {
        if (event.target.closest('[data-cbm-add-field]')) {
            fieldList.insertAdjacentHTML('beforeend', fieldTemplate.innerHTML.replaceAll('__INDEX__', String(nextIndex++)))
            toggleItems(fieldList.lastElementChild)
        }
        const removeButton = event.target.closest('[data-cbm-remove-field]')
        if (removeButton) {
            removeButton.closest('[data-cbm-field]').remove()
        }
    })
    form.addEventListener('change', (event) => {
        if (event.target.matches('[data-cbm-type]')) {
            toggleItems(event.target.closest('[data-cbm-field]'))
        }
    })
    contentType.addEventListener('change', toggleContentType)
    fieldList.querySelectorAll('[data-cbm-field]').forEach(toggleItems)
    toggleContentType()
}
