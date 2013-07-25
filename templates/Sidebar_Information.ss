<div class='cms-sitetree-information'>
	<p class="meta-info"><% _t('Sidebar.LASTSAVED', 'Last saved') %> $LastEdited.Ago(0)
	<% if $ExistsOnLive %>
		<br /><% _t('Sidebar.LASTPUBLISHED', 'Last published') %> $Live.LastEdited.Ago(0)
	<% else %>
		<br /><em><% _t('Sidebar.NOTPUBLISHED', 'Not published') %></em>
	<% end_if %>
	</p>
</div>