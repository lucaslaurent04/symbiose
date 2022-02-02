<?php
namespace documents;

use equal\orm\Model;

class DocumentTag extends Model {

    public static function getColumns() {
        return [
            'name' => [
                'type'              => 'string',
                'description'       => "Name of the document Tag (used for all variants).",
                'required'          => true
            ],
            'description' => [
                'type'              => 'string',
                'description'       => "Short string describing the purpose and usage of the category."
            ],
            'documents_ids' => [
                'type'              => 'many2many',
                'foreign_object'    => 'documents\Document',
                'foreign_field'     => 'tags_ids',
                'rel_table'         =>'documents_rel_document_tag',
                'rel_foreign_key'   => 'document_id',
                'rel_local_key'     => 'tag_id',
            ],
        ];
    }   
}