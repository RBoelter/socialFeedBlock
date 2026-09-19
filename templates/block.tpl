{if !empty($socialFeedPosts)}
    <link rel="stylesheet" href="{$socialFeedStylesheet|escape}">
    <div class="pkp_block block_socialFeed">
        <h2 class="title">{$socialFeedTitle|escape}</h2>
        <div class="content">
            <ul class="socialFeed_posts">
                {foreach from=$socialFeedPosts item=post}
                    <li class="socialFeed_post">
                        {if $post.repostedBy}
                            <p class="socialFeed_repost">{translate key="plugins.blocks.socialFeed.repostedBy" name=$post.repostedBy|escape}</p>
                        {/if}
                        <p class="socialFeed_author">
                            <strong class="socialFeed_name">{$post.authorName|escape}</strong>
                            <span class="socialFeed_handle">{$post.authorHandle|escape}</span>
                        </p>
                        {if $post.contentWarning}
                            <p class="socialFeed_warning">{$post.contentWarning|escape}</p>
                        {/if}
                        {if $post.html}
                            <div class="socialFeed_body">{$post.html}</div>
                        {/if}
                        {if $post.hasMedia}
                            <p class="socialFeed_media">
                                <a href="{$post.url|escape}" target="_blank" rel="nofollow noopener noreferrer">{translate key="plugins.blocks.socialFeed.hasMedia"}</a>
                            </p>
                        {/if}
                        <p class="socialFeed_meta">
                            <a href="{$post.url|escape}" target="_blank" rel="nofollow noopener noreferrer">
                                <time datetime="{$post.createdAt|escape}">{$post.createdAt|date_format:$dateFormatLong}</time>
                            </a>
                        </p>
                        {if $post.replyCount || $post.repostCount || $post.likeCount}
                            <ul class="socialFeed_stats">
                                {if $post.replyCount}<li>{translate key="plugins.blocks.socialFeed.replies" number=$post.replyCount}</li>{/if}
                                {if $post.repostCount}<li>{translate key="plugins.blocks.socialFeed.reposts" number=$post.repostCount}</li>{/if}
                                {if $post.likeCount}<li>{translate key="plugins.blocks.socialFeed.likes" number=$post.likeCount}</li>{/if}
                            </ul>
                        {/if}
                    </li>
                {/foreach}
            </ul>
            {if $socialFeedProfileUrl}
                <p class="socialFeed_more">
                    <a href="{$socialFeedProfileUrl|escape}" target="_blank" rel="nofollow noopener noreferrer">{translate key="plugins.blocks.socialFeed.more"}</a>
                </p>
            {/if}
        </div>
    </div>
{/if}
