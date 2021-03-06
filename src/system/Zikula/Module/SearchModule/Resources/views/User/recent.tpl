{gt text="Recent searches" assign=templatetitle domain='zikula'}
{include file='User/menu.tpl'}

<h3>{$templatetitle}</h3>
<table class="table table-bordered table-striped">
    <thead>
        <tr>
            <th>{gt text="Search keywords" domain='zikula'}</th>
            <th>{gt text="Number of searches" domain='zikula'}</th>
            <th>{gt text="Date of last search" domain='zikula'}</th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$recentsearches item='recentsearch'}
        <tr>
            <td><a href="{route name='zikulasearchmodule_user_search' q=$recentsearch.search}">{$recentsearch.search|replace:' ':', '|safetext}</a></td>
            <td>{$recentsearch.count|safetext}</td>
            <td>{$recentsearch.date->getTimestamp()|date_format}</td>
        </tr>
        {foreachelse}
        <tr class="table table-borderedempty"><td colspan="3">{gt text="No items found." domain='zikula'}</td></tr>
        {/foreach}
    </tbody>
</table>
