<?php
/*
Plugin Name: StayPress Enquiry plugin
Version: 1.2
Plugin URI: http://staypress.com
Description: Gravity forms integration plugin to handle the staypress enquiry forms
Author: StayPress
Author URI: http://staypress.com

Copyright 2010  (email: support@staypress.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

require_once('classes/common.php');

class sp_enquiry_gf_integration {

	var $options;
	var $formdetail;
	var $fields;

	var $id_field;

	function __construct() {

		// Integration into Gravity Forms
		add_action("init", array(&$this, 'init_plugin'), 1);
		//add_filter("gform_field_input", array('sp_enquiry_gf_integration', 'handle_gf_shortcodes'), 10, 5);
		add_filter("gform_add_field_buttons", array(&$this, 'add_gf_field_group'), 99);
		add_filter("gform_tooltips", array(&$this, 'add_gf_tooltips'));
		add_action("gform_editor_js", array(&$this, 'add_gf_js'));
		// For emails to owners as well as administrators
		add_action("init", array(&$this, 'setup_gf_to_filter'), 1);

		// Integration into StayPress property options
		add_action( 'staypress_property_options_form', array(&$this, 'show_property_gf_options') );
		add_action( 'staypress_property_postoptions_update', array(&$this, 'update_property_gf_options') );

		// Adding in booking links
		add_action("gform_entry_info", array(&$this, 'handle_gf_entry_info_actions'), 99, 2);
		add_action("gform_entries_first_column_actions", array(&$this, 'handle_gf_first_column_actions'), 10, 5);

		//prepoplulate filter
		add_filter('staypress_booking_prepopulate', array(&$this, 'prepopulate_booking'), 10, 2);

	}

	function sp_enquiry_gf_integration() {
		$this->__construct();
	}

	function init_plugin() {

		$this->options = SPECommon::get_option('property_enquiry_options', array());

		if(!IS_ADMIN) {
			add_filter("gform_field_value_[propertyid]", array(&$this, 'handle_gf_parameter_value_propertyid'));
			add_filter("gform_field_value_[propertyreference]", array(&$this, 'handle_gf_parameter_value_propertyref'));
			add_filter("gform_field_value_[propertypermalink]", array(&$this, 'handle_gf_parameter_value_propertypermalink'));
			add_filter("gform_field_value_[propertytitle]", array(&$this, 'handle_gf_parameter_value_propertytitle'));
			add_filter("gform_field_value_[propertycountry]", array(&$this, 'handle_gf_parameter_value_propertycountry'));
			add_filter("gform_field_value_[propertyregion]", array(&$this, 'handle_gf_parameter_value_propertyregion'));
			add_filter("gform_field_value_[propertytown]", array(&$this, 'handle_gf_parameter_value_propertytown'));
			add_filter("gform_field_value_[propertycontactname]", array(&$this, 'handle_gf_parameter_value_propertycontactname'));
			add_filter("gform_field_value_[propertycontacttel]", array(&$this, 'handle_gf_parameter_value_propertycontacttel'));
			add_filter("gform_field_value_[propertycontactemail]", array(&$this, 'handle_gf_parameter_value_propertycontactemail'));

		} else {
			if(class_exists('RGFormsModel')) {
				// grab the form
				$this->form = RGFormsModel::get_form_meta($this->options['enquiry_form_gf_id']);
				$this->fields = $this->form['fields'];
			}
		}



		//$to = apply_filters("gform_notification_email_{$form_id}" , apply_filters("gform_notification_email", $email_to, $lead), $lead);

	}

	function setup_gf_to_filter() {

		if(!empty($this->options['enquiry_form_gf_id'])) {
			add_filter("gform_notification_email_" . $this->options['enquiry_form_gf_id'], array(&$this, 'send_to_gf_owners'), 10, 2);
		}
	}

	function is_valid_email($email)
	{
		return (eregi ("^([a-z0-9_]|\\-|\\.)+@(([a-z0-9_]|\\-)+\\.)+[a-z]{2,4}$", $email));
	}

	function send_to_gf_owners( $to, $lead ) {

		if(empty($this->options['enquiry_form_gf_property_id']) || !function_exists('sp_get_contact')) {
			return $to;
		}

		$this->id_field = $this->options['enquiry_form_gf_property_id'];

		if(!empty($this->id_field) && !empty($lead[$this->id_field])) {

			$contacts = sp_get_contact($lead[$this->id_field]);

			if(!empty($contacts)) {
				$contact = array_shift($contacts);
				$contactmetadata = get_post_custom($contact->ID);

				$email = array_shift($contactmetadata['contact_email']);

				if(!empty($email) && $this->is_valid_email($email)) {
					return $email;
				}
			}

			// Still here, that means no contact so send to owner.
			$owner_id = sp_get_owner($lead[$this->id_field]);
			if($owner_id !== false) {
				$user = new WP_User($owner_id);

				if(!empty($user->user_email) && $this->is_valid_email($user->user_email)) {
					return $user->user_email;
				}
			}
		}

		return $to;

	}

	function handle_gf_first_column_actions( $form_id, $field_id, $value, $lead, $query_string ) {

		if($form_id == $this->options['enquiry_form_gf_id'] && !empty($this->options['enquiry_form_gf_property_id'])) {

			$this->id_field = $this->options['enquiry_form_gf_property_id'];

			if(!empty($this->id_field) && !empty($lead[$this->id_field])) {
				if( current_user_can('edit_booking') ) {
					// Current user can create a booking
					?>
						<span class="edit">
							|
	                        <a id="make_booking_<?php echo $lead["id"] ?>" title="Make a booking for this enquiry" href="admin.php?page=booking-add&amp;lead=<?php echo $lead['id'];  ?>" style=""><?php _e("Book", "enquiry"); ?></a>
	                    </span>
					<?php
				}

			}
		}

	}

	function handle_gf_entry_info_actions( $form_id, $lead) {
		if($form_id == $this->options['enquiry_form_gf_id'] && !empty($this->options['enquiry_form_gf_property_id'])) {

			$this->id_field = $this->options['enquiry_form_gf_property_id'];

			if(!empty($this->id_field) && !empty($lead[$this->id_field])) {
				if( current_user_can('edit_booking') ) {
					// Current user can create a booking
					_e('Booking', 'enquiry');
					?>
					: <a id="make_booking_<?php echo $lead["id"] ?>" title="Make a booking for this enquiry" href="admin.php?page=booking-add&amp;lead=<?php echo $lead['id'];  ?>" style=""><?php _e("Transfer details to booking", "enquiry"); ?></a>
	                   <br/><br/>
					<?php
				}

			}
		}
	}

	function prepopulate_booking( $booking, $lead_id ) {

		if(!class_exists('RGFormsModel')) {
			return $booking;
		}

		$lead = RGFormsModel::get_lead( $lead_id );

		$booking->property_id = $lead[ $this->options['enquiry_form_gf_property_id'] ];
		$booking->startdate = $lead[ $this->options['enquiry_form_gf_start_id'] ];
		$booking->enddate = $lead[ $this->options['enquiry_form_gf_end_id'] ];

		// Add the contact
		$booking->contact = array();

		$contact = new stdClass();
		$contact->post_title = $lead[ $this->options['enquiry_form_gf_name_id'] ];
		$contact->metadata = array();

		$contact->metadata['contact_email'] = array( $lead[ $this->options['enquiry_form_gf_email_id'] ] );
		$contact->metadata['contact_tel'] = array( $lead[ $this->options['enquiry_form_gf_tel_id'] ] );

		array_push( $booking->contact, $contact );

		return $booking;

	}

	// Short code functions
	function handle_gf_parameter_value_propertyid( $value ) {
		$value = do_shortcode("[propertyid]");
		return $value;
	}

	function handle_gf_parameter_value_propertyref( $value ) {
		$value = do_shortcode("[propertyreference]");
		return $value;
	}

	function handle_gf_parameter_value_propertypermalink( $value ) {
		$value = do_shortcode("[propertypermalink]");
		return $value;
	}

	function handle_gf_parameter_value_propertytitle( $value ) {
		$value = do_shortcode("[propertytitle]");
		return $value;
	}

	function handle_gf_parameter_value_propertycountry( $value ) {
		$value = do_shortcode("[propertycountry itemislink=no]");
		return $value;
	}

	function handle_gf_parameter_value_propertyregion( $value ) {
		$value = do_shortcode("[propertyregion itemislink=no]");
		return $value;
	}

	function handle_gf_parameter_value_propertytown( $value ) {
		$value = do_shortcode("[propertytown itemislink=no]");
		return $value;
	}

	function handle_gf_parameter_value_propertycontactname( $value ) {
		$value = do_shortcode("[propertycontactname]");
		return $value;
	}

	function handle_gf_parameter_value_propertycontacttel( $value ) {
		$value = do_shortcode("[propertycontacttel]");
		return $value;
	}

	function handle_gf_parameter_value_propertycontactemail( $value ) {
		$value = do_shortcode("[propertycontactemail]");
		return $value;
	}

	function handle_gf_shortcodes( $blank, $field, $value, $lead_id, $form_id ) {

		if(RGFormsModel::get_input_type($field) == 'hidden' && strpos($field['inputName'], '[') !== false) {
			$field_type = IS_ADMIN ? "text" : "hidden";
		    $class_attribute = IS_ADMIN ? "" : "class='gform_hidden'";

			$id = $field["id"];
	        $field_id = IS_ADMIN || $form_id == 0 ? "input_$id" : "input_" . $form_id . "_$id";
	        $form_id = IS_ADMIN && empty($form_id) ? $_GET["id"] : $form_id;
			$disabled_text = (IS_ADMIN && RG_CURRENT_VIEW != "entry") ? "disabled='disabled'" : "";

			$scvalue = $field['inputName'];

			if(strpos($scvalue, '[') !== false && !IS_ADMIN ) {
				$value = do_shortcode($scvalue);
			}

		    return sprintf("<input name='input_%d' id='%s' type='$field_type' $class_attribute value='%s' %s/>", $id, $field_id, esc_attr($value), $disabled_text);

		}

	}

	function add_gf_field_group( $field_groups ) {

		$field_groups[] = array(	"name"				=>	'staypress_fields',
									"label"				=>	__('StayPress Fields', 'enquiry'),
									"fields"			=>	array(
																	array(	"class"		=>	'button',
																			"value"		=>	__('Property ID','enquiry'),
																			"onclick"	=>	"StartAddSPField('propertyid');"
																		),
																	array(	"class"		=>	'button',
																			"value"		=>	__('Property Ref','enquiry'),
																			"onclick"	=>	"StartAddSPField('propertyref');"
																		),
																	array(	"class"		=>	'button',
																			"value"		=>	__('Property URL','enquiry'),
																			"onclick"	=>	"StartAddSPField('propertypermalink');"
																		),
																	array(	"class"		=>	'button',
																			"value"		=>	__('Property Title','enquiry'),
																			"onclick"	=>	"StartAddSPField('propertytitle');"
																		),
																	array(	"class"		=>	'button',
																			"value"		=>	__('Property Country','enquiry'),
																			"onclick"	=>	"StartAddSPField('propertycountry');"
																		),
																	array(	"class"		=>	'button',
																			"value"		=>	__('Property Region','enquiry'),
																			"onclick"	=>	"StartAddSPField('propertyregion');"
																		),
																	array(	"class"		=>	'button',
																			"value"		=>	__('Property Town','enquiry'),
																			"onclick"	=>	"StartAddSPField('propertytown');"
																		),
																	array(	"class"		=>	'button',
																			"value"		=>	__('Contact Name','enquiry'),
																			"onclick"	=>	"StartAddSPField('contactname');"
																		),
																	array(	"class"		=>	'button',
																			"value"		=>	__('Contact Email','enquiry'),
																			"onclick"	=>	"StartAddSPField('contactemail');"
																		),
																	array(	"class"		=>	'button',
																			"value"		=>	__('Contact Tel','enquiry'),
																			"onclick"	=>	"StartAddSPField('contacttel');"
																		)
																)
								);

		return $field_groups;
	}

	function add_gf_tooltips( $gf_tooltips ) {

		$gf_tooltips["form_staypress_fields"] = "<h6>" . __("StayPress Fields", "enquiry") . "</h6>" . __("StayPress Fields allow you to add hidden fields on the form containing information about the currently viewed property.", "enquiry");


		return $gf_tooltips;
	}

	function add_gf_js() {

		?>
		<script type="text/javascript">
		//-------------------------------------------------
		//STAYPRESS CUSTOMISATION JS
		//-------------------------------------------------
		function CreateSPField(id, type){
		     var field = new Field(id, 'hidden');
		     SetSPDefaultValues(field, type);

		     return field;
		}

		function StartAddSPField(type){

		    if(! CanFieldBeAdded('hidden'))
		        return;

		    var nextId = GetNextFieldId();
		    var field = CreateSPField(nextId, type);

		    var mysack = new sack("<?php echo admin_url("admin-ajax.php")?>?id=" + form.id);
		    mysack.execute = 1;
		    mysack.method = 'POST';
		    mysack.setVar( "action", "rg_add_field" );
		    mysack.setVar( "rg_add_field", "<?php echo wp_create_nonce("rg_add_field") ?>" );
		    mysack.setVar( "field", jQuery.toJSON(field) );
		    mysack.encVar( "cookie", document.cookie, false );
		    mysack.onError = function() { alert('<?php _e("Ajax error while adding field", "gravityforms") ?>' )};
		    mysack.runAJAX();

		    return true;
		}


		function SetSPDefaultValues(field, type){

		    //var inputType = GetInputType(field);
		    var inputType = type;
			switch(inputType){

		        case "propertyid" :
		            field.inputs = null;
		            if(!field.label)
		                field.label = "<?php _e("Property ID", "enquiry"); ?>";
						field.allowsPrepopulate = true;
						field.inputName = '[propertyid]';
		            break;
				case "propertyref" :
			        field.inputs = null;
			        if(!field.label)
		             	field.label = "<?php _e("Property Reference", "enquiry"); ?>";
						field.allowsPrepopulate = true;
						field.inputName = '[propertyreference]';
			        break;
				case "propertypermalink" :
			        field.inputs = null;
			        if(!field.label)
		             	field.label = "<?php _e("Property URL", "enquiry"); ?>";
						field.allowsPrepopulate = true;
						field.inputName = '[propertypermalink]';
			        break;
				case "propertytitle" :
			        field.inputs = null;
			        if(!field.label)
		             	field.label = "<?php _e("Property Title", "enquiry"); ?>";
						field.allowsPrepopulate = true;
						field.inputName = '[propertytitle]';
			        break;
				case "propertycountry" :
			        field.inputs = null;
			        if(!field.label)
		             	field.label = "<?php _e("Property Country", "enquiry"); ?>";
						field.allowsPrepopulate = true;
						field.inputName = '[propertycountry]';
			        break;
				case "propertyregion" :
			        field.inputs = null;
			        if(!field.label)
		             	field.label = "<?php _e("Property Region", "enquiry"); ?>";
						field.allowsPrepopulate = true;
						field.inputName = '[propertyregion]';
			        break;
				case "propertytown" :
			        field.inputs = null;
			        if(!field.label)
		             	field.label = "<?php _e("Property Town", "enquiry"); ?>";
						field.allowsPrepopulate = true;
						field.inputName = '[propertytown]';
			        break;
				case "contactname" :
			        field.inputs = null;
			        if(!field.label)
		             	field.label = "<?php _e("Contact Name", "enquiry"); ?>";
						field.allowsPrepopulate = true;
						field.inputName = '[propertycontactname]';
			        break;
				case "contacttel" :
			        field.inputs = null;
			        if(!field.label)
		             	field.label = "<?php _e("Contact Telephone", "enquiry"); ?>";
						field.allowsPrepopulate = true;
						field.inputName = '[propertycontacttel]';
			        break;
				case "contactemail" :
			        field.inputs = null;
			        if(!field.label)
		             	field.label = "<?php _e("Contact Email", "enquiry"); ?>";
						field.allowsPrepopulate = true;
						field.inputName = '[propertycontactemail]';
			        break;

		        break;
		     }

		    if(window["SetDefaultValues_" + inputType])
		        field = window["SetDefaultValues_" + inputType](field);
		}
		</script>
		<?php

	}

	function update_property_gf_options( $ignoreproperty ) {

		$formoption = array();

		$formoption['enquiry_form_gf_id'] = $_POST['enquiry_form_gf_id'];

		$formoption['enquiry_form_gf_property_id'] = $_POST['enquiry_form_gf_property_id'];
		$formoption['enquiry_form_gf_start_id'] = $_POST['enquiry_form_gf_start_id'];
		$formoption['enquiry_form_gf_end_id'] = $_POST['enquiry_form_gf_end_id'];
		$formoption['enquiry_form_gf_name_id'] = $_POST['enquiry_form_gf_name_id'];
		$formoption['enquiry_form_gf_email_id'] = $_POST['enquiry_form_gf_email_id'];
		$formoption['enquiry_form_gf_tel_id'] = $_POST['enquiry_form_gf_tel_id'];

		SPECommon::update_option('property_enquiry_options', $formoption);

	}

	function show_property_gf_options( $propertyoptions ) {

		$formoption = SPECommon::get_option('property_enquiry_options', array());

		echo "<h3>" . __('Enquiry form selection','enquiry') . "</h3>";

		if(!class_exists('GFCommon') || !class_exists('RGFormsModel')) {
			// No gravity forms
			echo "<p>" . __('You need the Gravity Forms plugin to create and select an enquiry form here.','enquiry') . "</p>";
			echo "<p>" . __('Grab Gravity Forms <a href="https://www.e-junkie.com/ecom/gb.php?cl=54585&c=ib&aff=132571" target="ejejcsingle">here</a>.','enquiry') . "</p>";
		} else {
			echo "<p>" . __('Select the form from your list of Gravity Forms to use for enquiries.','enquiry') . "</p>";

			echo "<table class='form-table'>";
			echo "<tbody>";

			echo "<tr valign='top'>";
			echo "<th scope='row'>" . __('Gravity form','enquiry') . "</th>";
			echo "<td>";

			$forms = RGFormsModel::get_forms($active, "title");
			echo "<select name='enquiry_form_gf_id'>";
				echo "<option value=''></option>";
				foreach($forms as $form) {
					echo "<option value='" . $form->id . "'";
					if(isset($this->options['enquiry_form_gf_id']) && $this->options['enquiry_form_gf_id'] == $form->id) {
						echo " selected='selected'";
					}
					echo ">" . $form->title . "</option>";
				}
			echo "</select>";

			echo "</td>";
			echo "</tr>";

			echo "</tbody>";
			echo "</table>";

			if(!empty($this->options['enquiry_form_gf_id']) && !empty($this->fields)) {
				// a form has been entered
				echo "<p><br/>" . __('Select the fields from your form to match booking information fields.','enquiry') . "</p>";

				echo "<table class='form-table'>";
				echo "<tbody>";

				echo "<tr valign='top'>";
				echo "<th scope='row'>" . __('Property ID field','enquiry') . "</th>";
				echo "<td>";

				echo "<select name='enquiry_form_gf_property_id'>";
					echo "<option value=''></option>";
					foreach($this->fields as $field) {
						echo "<option value='" . $field['id'] . "'";
						if(isset($this->options['enquiry_form_gf_property_id']) && $this->options['enquiry_form_gf_property_id'] == $field['id']) {
							echo " selected='selected'";
						}
						echo ">" . $field['label'] . "</option>";
					}
				echo "</select>";

				echo "</td>";
				echo "</tr>";

				echo "<tr valign='top'>";
				echo "<th scope='row'>" . __('Start date field','enquiry') . "</th>";
				echo "<td>";

				echo "<select name='enquiry_form_gf_start_id'>";
					echo "<option value=''></option>";
					foreach($this->fields as $field) {
						echo "<option value='" . $field['id'] . "'";
						if(isset($this->options['enquiry_form_gf_start_id']) && $this->options['enquiry_form_gf_start_id'] == $field['id']) {
							echo " selected='selected'";
						}
						echo ">" . $field['label'] . "</option>";
					}
				echo "</select>";

				echo "</td>";
				echo "</tr>";

				echo "<tr valign='top'>";
				echo "<th scope='row'>" . __('End date field','enquiry') . "</th>";
				echo "<td>";

				echo "<select name='enquiry_form_gf_end_id'>";
					echo "<option value=''></option>";
					foreach($this->fields as $field) {
						echo "<option value='" . $field['id'] . "'";
						if(isset($this->options['enquiry_form_gf_end_id']) && $this->options['enquiry_form_gf_end_id'] == $field['id']) {
							echo " selected='selected'";
						}
						echo ">" . $field['label'] . "</option>";
					}
				echo "</select>";

				echo "</td>";
				echo "</tr>";

				echo "<tr valign='top'>";
				echo "<th scope='row'>" . __('Contact name field','enquiry') . "</th>";
				echo "<td>";

				echo "<select name='enquiry_form_gf_name_id'>";
					echo "<option value=''></option>";
					foreach($this->fields as $field) {
						echo "<option value='" . $field['id'] . "'";
						if(isset($this->options['enquiry_form_gf_name_id']) && $this->options['enquiry_form_gf_name_id'] == $field['id']) {
							echo " selected='selected'";
						}
						echo ">" . $field['label'] . "</option>";
					}
				echo "</select>";

				echo "</td>";
				echo "</tr>";

				echo "<tr valign='top'>";
				echo "<th scope='row'>" . __('Contact email field','enquiry') . "</th>";
				echo "<td>";

				echo "<select name='enquiry_form_gf_email_id'>";
					echo "<option value=''></option>";
					foreach($this->fields as $field) {
						echo "<option value='" . $field['id'] . "'";
						if(isset($this->options['enquiry_form_gf_email_id']) && $this->options['enquiry_form_gf_email_id'] == $field['id']) {
							echo " selected='selected'";
						}
						echo ">" . $field['label'] . "</option>";
					}
				echo "</select>";

				echo "</td>";
				echo "</tr>";

				echo "<tr valign='top'>";
				echo "<th scope='row'>" . __('Contact telephone field','enquiry') . "</th>";
				echo "<td>";

				echo "<select name='enquiry_form_gf_tel_id'>";
					echo "<option value=''></option>";
					foreach($this->fields as $field) {
						echo "<option value='" . $field['id'] . "'";
						if(isset($this->options['enquiry_form_gf_tel_id']) && $this->options['enquiry_form_gf_tel_id'] == $field['id']) {
							echo " selected='selected'";
						}
						echo ">" . $field['label'] . "</option>";
					}
				echo "</select>";

				echo "</td>";
				echo "</tr>";

			}
			echo "</tbody>";
			echo "</table>";

		}

	}

}

$sp_enquiry_gf_integration = new sp_enquiry_gf_integration();

?>