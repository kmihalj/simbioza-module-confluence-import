<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Value;

/**
 * HR: Daje pretvaraču kontrolirani lokalni kontekst za makroe koji ovise o stablu ili privitcima.
 * EN: Supplies the converter with controlled local context for tree- or attachment-dependent macros.
 */
final readonly class ConfluenceMacroContext
{
    public string $currentPageId;

    /**
     * HR: Sprema trenutačnu logičku stranicu, planirane ciljeve cijelog područja i privitke stranice.
     * EN: Stores the current logical page, all planned Workspace targets, and current-page attachments.
     *
     * @param array<string,array{title:string,path:string,parent_id:string,sort_order:int,workspace_slug?:string,node_slug?:string,labels?:list<string>,creator?:string,updated_at?:string,tasks?:list<array{id:string,native_uuid:string,text:string,complete:bool,due_date:string,assignee:string}>}> $pages
     * @param array<string,string> $attachments
     * @param array<string,string> $users
     * @param array<string,string> $calendars
     */
    public function __construct(
        int|string $currentPageId,
        public array $pages,
        public array $attachments,
        public array $users = [],
        public array $calendars = [],
    ) {
        // HR: PHP brojčane XML identifikatore pretvara u cjelobrojne ključeve polja; lokalno ih uvijek čuvamo kao tekst.
        // EN: PHP converts numeric XML identifiers into integer array keys; locally we always retain them as strings.
        $this->currentPageId = (string)$currentPageId;
    }
}
