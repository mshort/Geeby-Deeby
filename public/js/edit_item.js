var ItemEditor = function() {
    this.type = "Item";
    this.saveFields = {
        'series_id': { 'id': '#Series_ID' },
        'name': { 'id': '#Item_Name', emptyError: 'Item name cannot be blank.' },
        'errata': { 'id': '#Item_Errata' },
        'thanks': { 'id': '#Item_Thanks' },
        'material': { 'id': '#Material_Type_ID' },
        // Length and endings are actually Edition fields, but we load the data here
        // for convenience when creating items in some contexts.
        'len': { 'id': '#Item_Length' },
        'endings': { 'id': '#Item_Endings' },
        // Edition_ID is only set when we are creating a child edition....
        'edition_id': { 'id': '#Edition_ID' }
    };
};
BaseEditor.prototype.registerSubclass(ItemEditor);
var Item = new ItemEditor();