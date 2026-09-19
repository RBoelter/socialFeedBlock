{literal}
<script>
    $(function () {
        var $form = $('#socialFeedSettings');
        $form.pkpHandler('$.pkp.controllers.form.AjaxFormHandler');

        // Only the fields of the selected network are shown
        function showSelectedNetwork() {
            var network = $form.find('select[name="network"]').val();
            $form.find('.socialFeedNetworkFields').each(function () {
                $(this).toggle($(this).data('network') === network);
            });
        }
        $form.find('select[name="network"]').on('change', showSelectedNetwork);
        showSelectedNetwork();
    });
</script>
{/literal}
<form
    class="pkp_form"
    id="socialFeedSettings"
    method="POST"
    action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="blocks" plugin=$pluginName verb="settings" save=true}"
>
    {csrf}
    {include file="controllers/notification/inPlaceNotification.tpl" notificationId="socialFeedSettingsNotification"}
    {fbvFormArea id="socialFeedSettingsArea"}
        <p class="description">{translate key="plugins.blocks.socialFeed.settings.privacy"}</p>

        {fbvFormSection title="plugins.blocks.socialFeed.settings.blockTitle"}
            {fbvElement type="text" id="blockTitle" value=$blockTitle label="plugins.blocks.socialFeed.settings.blockTitle.desc"}
        {/fbvFormSection}

        {fbvFormSection title="plugins.blocks.socialFeed.settings.network" for="network"}
            {fbvElement type="select" id="network" name="network" from=$networkOptions translate="true" selected=$network size=$fbvStyles.size.MEDIUM}
        {/fbvFormSection}

        <div class="socialFeedNetworkFields" data-network="mastodon">
            {fbvFormSection title="plugins.blocks.socialFeed.settings.mastodonInstance"}
                {fbvElement type="text" id="mastodonInstance" value=$mastodonInstance label="plugins.blocks.socialFeed.settings.mastodonInstance.desc"}
            {/fbvFormSection}
            {fbvFormSection title="plugins.blocks.socialFeed.settings.mastodonSource" for="mastodonSource"}
                {fbvElement type="select" id="mastodonSource" name="mastodonSource" from=$mastodonSourceOptions translate="true" selected=$mastodonSource size=$fbvStyles.size.MEDIUM}
            {/fbvFormSection}
            {fbvFormSection title="plugins.blocks.socialFeed.settings.mastodonHandle"}
                {fbvElement type="text" id="mastodonHandle" value=$mastodonHandle label="plugins.blocks.socialFeed.settings.mastodonHandle.desc"}
            {/fbvFormSection}
        </div>

        <div class="socialFeedNetworkFields" data-network="bluesky">
            {fbvFormSection title="plugins.blocks.socialFeed.settings.blueskySource" for="blueskySource"}
                {fbvElement type="select" id="blueskySource" name="blueskySource" from=$blueskySourceOptions translate="true" selected=$blueskySource size=$fbvStyles.size.MEDIUM}
            {/fbvFormSection}
            {fbvFormSection title="plugins.blocks.socialFeed.settings.blueskyActor"}
                {fbvElement type="text" id="blueskyActor" value=$blueskyActor label="plugins.blocks.socialFeed.settings.blueskyActor.desc"}
            {/fbvFormSection}
        </div>

        {fbvFormSection title="plugins.blocks.socialFeed.settings.postCount"}
            {fbvElement type="text" id="postCount" value=$postCount label=$postCountHelp subLabelTranslate=false size=$fbvStyles.size.SMALL}
        {/fbvFormSection}

        {fbvFormSection list=true}
            {fbvElement type="checkbox" id="excludeReplies" name="excludeReplies" value="1" checked=$excludeReplies label="plugins.blocks.socialFeed.settings.excludeReplies"}
            {fbvElement type="checkbox" id="excludeReposts" name="excludeReposts" value="1" checked=$excludeReposts label="plugins.blocks.socialFeed.settings.excludeReposts"}
        {/fbvFormSection}

        {fbvFormSection title="plugins.blocks.socialFeed.settings.cacheTtl"}
            {fbvElement type="text" id="cacheTtl" value=$cacheTtl label=$cacheTtlHelp subLabelTranslate=false size=$fbvStyles.size.SMALL}
        {/fbvFormSection}
    {/fbvFormArea}
    {fbvFormButtons submitText="common.save"}
</form>
