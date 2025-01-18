jQuery(document).ready(function($) {
    // Handle group select all checkboxes
    $('.cb-select-all').on('change', function() {
        var isChecked = $(this).prop('checked');
        $(this).closest('table').find('tbody input[type="checkbox"]').prop('checked', isChecked);
    });

    // Update group select all when individual checkboxes change
    $('input[name="products[]"]').on('change', function() {
        var $table = $(this).closest('table');
        var $groupCheckbox = $table.find('.cb-select-all');
        var totalCheckboxes = $table.find('input[name="products[]"]').length;
        var checkedCheckboxes = $table.find('input[name="products[]"]:checked').length;
        
        $groupCheckbox.prop('checked', totalCheckboxes === checkedCheckboxes);
    });

    // Handle form submission with validation and confirmation
    $('#cptsm2-delete-form').on('submit', function(e) {
        var checkedBoxes = $(this).find('input[name="products[]"]:checked');
        
        // validate that there are products selected
        if (checkedBoxes.length === 0) {
            e.preventDefault();
            alert(cptsm2_vars.no_products_selected);
            return false;
        }
        
        // confirm deletion
        if (!confirm(cptsm2_vars.confirm_delete)) {
            e.preventDefault();
            return false;
        }
    });
}); 