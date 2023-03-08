!function(){function t(t,e){(null==e||e>t.length)&&(e=t.length);for(var n=0,a=new Array(e);n<e;n++)a[n]=t[n];return a}var e;e=jQuery,Craft.Mfa=Garnish.Base.extend({$mfaLoginFormContainer:null,$mfaSetupFormContainer:null,$alternativeMfaLink:null,$alternativeMfaTypesContainer:null,$viewSetupBtns:null,$errors:null,$slideout:null,$removeSetupButton:null,$closeButton:null,$verifyButton:null,init:function(t){this.$mfaLoginFormContainer=e("#mfa-form"),this.$mfaSetupFormContainer=e("#mfa-setup"),this.$alternativeMfaLink=e("#alternative-mfa"),this.$alternativeMfaTypesContainer=e("#alternative-mfa-types"),this.$viewSetupBtns=this.$mfaSetupFormContainer.find("button.mfa-view-setup"),this.setSettings(t,Craft.Mfa.defaults),this.addListener(this.$alternativeMfaLink,"click","onAlternativeMfaType"),this.addListener(this.$viewSetupBtns,"click","onViewSetupBtnClick")},showMfaForm:function(t,n){this.$mfaLoginFormContainer.html("").append(t),n.addClass("mfa"),e("#login-form-buttons").hide();var a=this.$mfaLoginFormContainer.find(".submit");this.onSubmitResponse(a)},getCurrentMfaType:function(t){var e=t.attr("data-mfa-type");return void 0===e&&(e=null),e},submitLoginMfa:function(){var t=this,n=this.$mfaLoginFormContainer.find(".submit");n.addClass("loading");var a={mfaFields:{}};this.$mfaLoginFormContainer.find("input").each((function(t,n){a.mfaFields[e(n).attr("name")]=e(n).val()})),a.currentMethod=this.getCurrentMfaType(this.$mfaLoginFormContainer.find("#verifyContainer")),Craft.sendActionRequest("POST","users/verify-mfa",{data:a}).then((function(t){window.location.href=t.data.returnUrl})).catch((function(e){var a=e.response;t.onSubmitResponse(n),t.showError(a.data.message)}))},onViewSetupBtnClick:function(t){var n=this;t.preventDefault();var a={selectedMethod:this.getCurrentMfaType(e(t.currentTarget))};Craft.sendActionRequest("POST","mfa/setup-slideout-html",{data:a}).then((function(t){n.slideout=new Craft.Slideout(t.data.html),n.$errors=n.slideout.$container.find(".so-notice"),n.$closeButton=n.slideout.$container.find("button.close"),n.$verifyButton=n.slideout.$container.find("#mfa-verify"),n.$removeSetupButton=n.slideout.$container.find("#mfa-remove-setup"),n.addListener(n.$removeSetupButton,"click","onRemoveSetup"),n.addListener(n.$closeButton,"click","onClickClose"),n.addListener(n.$verifyButton,"click","onVerify"),n.slideout.on("close",(function(t){n.$removeSetupButton=null,n.slideout=null}))})).catch((function(t){var e=t.response;Craft.cp.displayError(e.data.message)}))},onClickClose:function(t){this.slideout.close()},onRemoveSetup:function(t){var n=this;t.preventDefault();var a=this.getCurrentMfaType(this.slideout.$container.find("#mfa-setup-form"));void 0===a&&(a=null);var r={currentMethod:a};Craft.sendActionRequest("POST",this.settings.removeSetup,{data:r}).then((function(n){e(t.currentTarget).remove(),Craft.cp.displayNotice(Craft.t("app","MFA setup removed."))})).catch((function(t){Craft.cp.displayError(t.response.data.message)})).finally((function(){n.slideout.close()}))},onVerify:function(t){var n=this;t.preventDefault();var a=this.slideout.$container.find("#mfa-verify");a.addClass("loading");var r={mfaFields:{}};this.slideout.$container.find("input").each((function(t,n){r.mfaFields[e(n).attr("name")]=e(n).val()})),r.currentMethod=this.getCurrentMfaType(this.slideout.$container.find("#mfa-setup-form")),Craft.sendActionRequest("POST","mfa/save-setup",{data:r}).then((function(t){n.onSubmitResponse(a),Craft.cp.displayNotice(Craft.t("app","MFA settings saved.")),n.slideout.close()})).catch((function(t){var e=t.response;n.onSubmitResponse(a),n.showError(e.data.message),Craft.cp.displayError(e.data.message)}))},onSubmitResponse:function(t){t.removeClass("loading")},showError:function(t){this.clearErrors(),e('<p class="error" style="display: none;">'+t+"</p>").appendTo(this.$errors).velocity("fadeIn")},clearErrors:function(){this.$errors.empty()},onAlternativeMfaType:function(t){var e=this.getCurrentMfaType(this.$mfaLoginFormContainer.find("#verifyContainer"));null===e&&(this.$alternativeMfaLink.hide(),this.showError(Craft.t("app","No alternative MFA methods available.")));var n={currentMethod:e};this.getAlternativeMfaTypes(n)},getAlternativeMfaTypes:function(t){var e=this;Craft.sendActionRequest("POST","mfa/get-alternative-mfa-types",{data:t}).then((function(t){void 0!==t.data.alternativeTypes&&e.showAlternativeMfaTypes(t.data.alternativeTypes)})).catch((function(t){var n=t.response;e.showError(n.data.message)}))},showAlternativeMfaTypes:function(n){var a=this,r=Object.entries(n).map((function(e){var n,a,r=(a=2,function(t){if(Array.isArray(t))return t}(n=e)||function(t,e){var n=null==t?null:"undefined"!=typeof Symbol&&t[Symbol.iterator]||t["@@iterator"];if(null!=n){var a,r,i=[],o=!0,s=!1;try{for(n=n.call(t);!(o=(a=n.next()).done)&&(i.push(a.value),!e||i.length!==e);o=!0);}catch(t){s=!0,r=t}finally{try{o||null==n.return||n.return()}finally{if(s)throw r}}return i}}(n,a)||function(e,n){if(e){if("string"==typeof e)return t(e,n);var a=Object.prototype.toString.call(e).slice(8,-1);return"Object"===a&&e.constructor&&(a=e.constructor.name),"Map"===a||"Set"===a?Array.from(e):"Arguments"===a||/^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(a)?t(e,n):void 0}}(n,a)||function(){throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.")}());return{key:r[0],value:r[1]}}));r.length>0&&r.forEach((function(t){a.$alternativeMfaTypesContainer.append('<li><button class="alternative-mfa-type" type="button" value="'+t.key+'">'+t.value.name+"</button></li>")})),this.$alternativeMfaLink.hide().after(this.$alternativeMfaTypesContainer),this.addListener(e(".alternative-mfa-type"),"click","onSelectAlternativeMfaType")},onSelectAlternativeMfaType:function(t){var n=this,a={selectedMethod:e(t.currentTarget).attr("value")};Craft.sendActionRequest("POST","mfa/load-alternative-mfa-type",{data:a}).then((function(t){void 0!==t.data.mfaForm&&(n.$mfaLoginFormContainer.html("").append(t.data.mfaForm),n.$alternativeMfaTypesContainer.html(""),n.$alternativeMfaLink.show(),n.onSubmitResponse())})).catch((function(t){t.response}))}},{defaults:{removeSetup:"mfa/remove-setup"}})}();
//# sourceMappingURL=mfa.js.map