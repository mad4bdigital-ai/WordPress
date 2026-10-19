import { __ } from '@wordpress/i18n';
import { Flex, TextControl } from '@wordpress/components';
import { useFields } from 'jet-form-builder-blocks-to-actions';
import {
	WideLine,
} from 'jet-form-builder-components';
import {
	ValidatedSelectControl,
} from 'jet-form-builder-actions';
import ContentTypeRow from './ContentTypeRow';
import FieldsMapRow from './FieldsMapRow';
import DefaultFieldsMapRow from './DefaultFieldsMapRow';
import { useSelect } from '@wordpress/data';
import { STORE_NAME } from '@/jet-forms-v2/Store';

function DeleteCustomContentType( props ) {

	const {
		      source,
		      settings,
		      onChangeSettingObj,
	      } = props;

	const { error, cctType } = useSelect(
		select => (
			{
				error: select( STORE_NAME ).getResolutionError(
					'getType',
					[ settings.type ],
				),
				cctType: select( STORE_NAME ).getType( settings.type ),
			}
		),
		[ settings.type ],
	);
console.log( source, props );
	const formFields = useFields( { withInner: false, placeholder: '--' } );

	return <Flex direction="column">
		<ContentTypeRow { ...props }/>
		<WideLine/>
		<ValidatedSelectControl
			label={ __( 'Who can delete the item', 'jet-engine' ) }
			value={ settings.permission }
			onChange={ permission => onChangeSettingObj( { permission } ) }
			options={ [
				{
					'value': 'permitted_users',
					'label': __( 'Users that have permission to delete it', 'jet-engine' )
				},
				{
					'value': 'logged_in',
					'label': __( 'Logged-in users', 'jet-engine' )
				},
				{
					'value': 'anybody',
					'label': __( 'Anybody', 'jet-engine' )
				},
			] }
		/>
		<ValidatedSelectControl
			label={ __( 'Item ID', 'jet-engine' ) }
			value={ settings.item_id }
			onChange={ item_id => onChangeSettingObj( { item_id } ) }
			options={ formFields }
		/>
	</Flex>;
}

export default DeleteCustomContentType;