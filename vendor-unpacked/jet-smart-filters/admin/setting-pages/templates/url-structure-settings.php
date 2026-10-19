<div class="jet-smart-filters-settings-page jet-smart-filters-settings-page__url-structure">
	<div class="url-structure-type">
		<div class="url-structure-type__header">
			<div class="cx-vui-title"><?php esc_html_e( 'URL Structure Type', 'jet-smart-filters' ); ?></div>
			<div class="cx-vui-subtitle"><?php esc_html_e( 'List of URL structure types', 'jet-smart-filters' ); ?></div>
			<cx-vui-radio
				name="url_structure_type"
				v-model="settings.url_structure_type"
				:optionsList="data.url_structure_type_options"
			>
			</cx-vui-radio>
		</div>
		<div class="rewritable-post-types"
			v-if="settings.url_structure_type === 'permalink'"
		>
			<div class="rewritable-post-types__header">
				<div class="cx-vui-title"><?php esc_html_e( 'Rewritable Post Types', 'jet-smart-filters' ); ?></div>
				<div class="cx-vui-subtitle"><?php esc_html_e( 'Post Types and their Taxonomies for which permalinks will be rewritten', 'jet-smart-filters' ); ?></div>
			</div>
			<div class="rewritable-post-types__list">
				<div
					class="rewritable-post-types__item"
					v-for="( value, prop, index ) in data.rewritable_post_types_options"
				>
					<cx-vui-switcher
						:key="index"
						:name="`rewritable-post-types-${prop}`"
						:label="value"
						:wrapper-css="[ 'equalwidth' ]"
						return-true="true"
						return-false="false"
						v-model="settings.rewritable_post_types[prop]"
					>
					</cx-vui-switcher>
				</div>
			</div>
		</div>
		<div class="url-taxonomy-type-name">
			<cx-vui-select
				label="<?php esc_html_e( 'Taxonomy term name type in URL', 'jet-smart-filters' ); ?>"
				:optionsList="data.url_taxonomy_term_name_options"
				:wrapper-css="[ 'equalwidth' ]"
				v-model="settings.url_taxonomy_term_name"
			/>
			<div v-if="settings.url_taxonomy_term_name==='slug'"
				 class="url-taxonomy-type-name-notification">
				<?php esc_html_e( 'All term slugs must be unique. Duplicate slugs may cause errors or unexpected behavior.', 'jet-smart-filters' ); ?>
			</div>
		</div>
		<div class="url-custom-symbols">
			<cx-vui-switcher
				class="url-custom-symbols-switcher"
				label="<?php esc_html_e( 'Use Custom URL Symbols', 'jet-smart-filters' ); ?>"
				:wrapper-css="[ 'equalwidth' ]"
				v-model="settings.use_url_custom_symbols">
			</cx-vui-switcher>
			<div v-if="settings.use_url_custom_symbols"
				 class="url-custom-symbols-list">
				<cx-vui-input
					label="<?php esc_html_e( 'Provider/Query ID delimiter', 'jet-smart-filters' ); ?>"
					placeholder=":"
					:wrapper-css="[ 'equalwidth' ]"
					v-model="settings.url_provider_id_delimiter"
				></cx-vui-input>
				<cx-vui-input
					label="<?php esc_html_e( 'Terms & Meta items separator', 'jet-smart-filters' ); ?>"
					placeholder=";"
					:wrapper-css="[ 'equalwidth' ]"
					v-model="settings.url_items_separator"
				></cx-vui-input>
				<cx-vui-input
					label="<?php esc_html_e( 'Key/Value delimiter', 'jet-smart-filters' ); ?>"
					placeholder=":"
					:wrapper-css="[ 'equalwidth' ]"
					v-model="settings.url_key_value_delimiter"
				></cx-vui-input>
				<cx-vui-input
					label="<?php esc_html_e( 'Value elements separator', 'jet-smart-filters' ); ?>"
					placeholder=","
					:wrapper-css="[ 'equalwidth' ]"
					v-model="settings.url_value_separator"
				></cx-vui-input>
				<cx-vui-input
					label="<?php esc_html_e( 'Query var suffix separator', 'jet-smart-filters' ); ?>"
					placeholder="!"
					:wrapper-css="[ 'equalwidth' ]"
					v-model="settings.url_var_suffix_separator"
				></cx-vui-input>
			</div>
		</div>
		<div class="url-aliases-section">
			<cx-vui-switcher
				class="use-url-aliases"
				name="use-url-aliases"
				label="<?php esc_html_e( 'Use URL Aliases', 'jet-smart-filters' ); ?>"
				description="<?php esc_html_e( 'Allow to replace selected parts of the filtered URLs with any alias words you want', 'jet-smart-filters' ); ?>"
				:wrapper-css="[ 'equalwidth' ]"
				return-true="true"
				return-false="false"
				v-model="settings.use_url_aliases">
			</cx-vui-switcher>
			<cx-vui-repeater
				v-if="settings.use_url_aliases === 'true'"
				class="url-aliases"
				name="url-aliases"
				buttonLabel="<?php esc_html_e( 'Add new alias', 'jet-smart-filters' ); ?>"
				buttonSize="mini"
				v-model="settings.url_aliases"
				@add-new-item="repeaterAddItem( {needle: '', replacement: ''}, settings.url_aliases )"
			>
				<cx-vui-repeater-item
					v-for="( alias, index ) in settings.url_aliases"
					class="url-alias"
					:class="{ 'cx-vui-repeater-item--last': settings.url_aliases.length == 1 }"
					:index="index"
					@delete-item="repeaterDeleteItem( index, settings.url_aliases )"
				>
					<cx-vui-input
						class="url-alias-needle"
						placeholder="<?php esc_html_e( 'Needle', 'jet-smart-filters' ); ?>"
						size="fullwidth"
						:value="alias.needle"
						@on-keypress="onAliasInputEvent($event, index, 'needle')"
						@on-blur="onAliasInputEvent($event, index, 'needle')"
					></cx-vui-input>
					<cx-vui-input
						class="url-alias-replacement"
						placeholder="<?php esc_html_e( 'Replacement', 'jet-smart-filters' ); ?>"
						size="fullwidth"
						:value="alias.replacement"
						@on-keypress="onAliasInputEvent($event, index, 'replacement')"
						@on-blur="onAliasInputEvent($event, index, 'replacement')"
					></cx-vui-input>
				</cx-vui-repeater-item>
			</cx-vui-repeater>
		</div>
		<div v-if="settings.use_url_aliases === 'true'"
			 class="url-aliases-example-section">
			<cx-vui-switcher
				class="use-url-aliases-example"
				name="use-url-aliases-example"
				label="<?php esc_html_e( 'See how the aliases will look', 'jet-smart-filters' ); ?>"
				return-true="true"
				return-false="false"
				v-model="settings.use_url_aliases_example">
			</cx-vui-switcher>
			<jsf-url-aliases-example
				v-if="settings.use_url_aliases_example === 'true'"
				v-model="settings.url_aliases_example"
				:aliases="settings.url_aliases"
				urlPrefix="<?php echo esc_url( home_url() ); ?>"
				:defaultUrl="data.url_aliases_example_default"
			/>
		</div>
		<div
			class="url-aliases-warning-text"
			v-if="settings.use_url_aliases === 'true'"
		>
			<p>
				For URL aliases to work correctly, both the Needle and Replacement values must be unique and must not appear elsewhere in the URL.
			</p>
			<p>
				Additionally, the following characters are not allowed in either field:<br>
				<code><</code>, <code>></code>, <code>#</code>, <code>%</code>, <code>{</code>, <code>}</code>, <code>`</code>, <code>'</code>, <code>\</code>, <code>|</code>, <code>^</code>
			</p>
		</div>
	</div>
</div>
